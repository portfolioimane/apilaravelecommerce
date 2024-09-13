<?php

namespace App\Http\Controllers\Api\Frontend;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Order;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\DB;





class CheckoutController extends Controller
{
    private $paypal;

    public function __construct()
    {
          $this->middleware('auth'); 
        $this->paypal = new PayPalClient;
        $this->paypal->setApiCredentials(config('paypal'));
        $this->paypal->setAccessToken($this->paypal->getAccessToken());
    }

public function checkout()
    {
        $userId = Auth::id();
        Log::info('Checkout initiated', ['user_id' => $userId]);

        $cart = Cart::where('user_id', $userId)->first();
        if (!$cart) {
            Log::error('Cart not found', ['user_id' => $userId]);
            return response()->json(['error' => 'Cart not found'], 404);
        }

        $cartItems = CartItem::where('cart_id', $cart->id)->with('product')->get();
        $total = $cartItems->sum(function ($item) {
            return $item->quantity * $item->price;
        });

        $shipping = 50; // Example shipping rate

        Log::info('Checkout successful', [
            'cart_items' => $cartItems->toArray(),
            'total' => $total,
            'shipping' => $shipping
        ]);

        return response()->json([
            'cartItems' => $cartItems,
            'total' => $total,
            'shipping' => $shipping
        ]);
    }

/**********************************stripe*************************/
public function processPayment(Request $request)
{
    \Log::info('processPayment called.', ['user_id' => Auth::id()]);

    // Set Stripe secret key
    Stripe::setApiKey(config('services.stripe.secret'));

    // Calculate the total amount in cents
    $amountInCents = $this->calculateAmountInCents();
     $totalAmount = $this->calculateAmount(); 

    \Log::info('Calculated amount in cents:', ['amount' => $amountInCents]);

   \Log::info('payment method:', ['payment_method' => $request->payment_method]);

    // Check if the amount is less than the minimum allowed
    if ($amountInCents < 50) {
        \Log::error('Amount is below the minimum charge amount allowed.', ['amount' => $amountInCents]);
        return $this->paymentFailed('The amount is below the minimum charge amount allowed.');
    }

    try {
        // Create a PaymentIntent with Stripe
        $paymentIntent = PaymentIntent::create([
            'amount' => $amountInCents,
            'currency' => 'mad',
            'payment_method' => $request->payment_method_id,
            'confirmation_method' => 'manual',
            'confirm' => true,
            'return_url' => route('payment.return'),
        ]);
        \Log::info('PaymentIntent created successfully.', ['paymentIntent' => $paymentIntent]);

        // Store the order in the database
        $order = $this->storeOrder($request, $request->payment_method,  $totalAmount, 'pending');

        // Check if the order is created successfully
        if ($order) {
            \Log::info('Order stored successfully.', ['order_id' => $order->id]);
        } else {
            \Log::error('Order could not be stored.');
        }

        // If the payment requires further action
        if ($paymentIntent->status === 'requires_action') {
            \Log::info('Payment requires additional action.', ['next_action' => $paymentIntent->next_action->redirect_to_url->url]);
            return response()->json(['redirect_url' => $paymentIntent->next_action->redirect_to_url->url]);
        } else {
            // Immediate success, clear the cart
          $this->clearUserCart();          
   \Log::info('Immediate success. Cart cleared.', ['orderId' => $order->id]);
              return response()->json( ['orderId' => $order->id]);

        }
    } catch (\Exception $e) {
        \Log::error('Payment processing failed.', ['error' => $e->getMessage()]);
        return $this->paymentFailed($e->getMessage());
    }
}


public function handlePaymentReturn(Request $request)
{
    $paymentIntentId = $request->query('payment_intent');

       \Log::info('payment method:', ['payment_method' => $request->payment_method]);


    if (!$paymentIntentId) {
        return redirect()->route('cancel')->with('error', 'Payment failed.');
    }

    try {
        $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

        if ($paymentIntent->status === 'succeeded') {
            $order = $this->storeOrder($request, $request->payment_method, $this->calculateAmount(), 'completed');

            if (!$order) {
                \Log::error('Order could not be created.');
                return $this->paymentFailed('Order could not be created.');
            }

             $this->clearUserCart();
               \Log::info('Cart Cleared.');

     return response()->json( ['orderId' => $order->id]);
        } else {
            return $this->paymentFailed('Payment failed.');
        }
    } catch (\Exception $e) {
        \Log::error('Payment Error:', ['error' => $e->getMessage()]);
        return $this->paymentFailed($e->getMessage());
    }
}




    /****************************************paypal *******************************/

public function createPayment(Request $request)
{
    $totalAmount = $this->calculateAmount(); // Amount in cents
    $totalAmountInDollars = number_format($totalAmount / 100, 2); // Convert cents to dollars

    \Log::info('Creating PayPal Payment', ['amount' => $totalAmountInDollars]);

    $paymentMethod = $request->input('payment_method'); // Retrieve payment method from the request
    \Log::info('Payment Method', ['payment_method' => $paymentMethod]);

 
    try {
        $paypalOrder = $this->paypal->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => 'transaction_test_number_' . $request->user()->id,
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => $totalAmountInDollars
                    ]
                ]
            ],
            'application_context' => [
                'cancel_url' => route('paypal.cancel'),
                'return_url' => route('paypal.success')
            ]
        ]);

        \Log::info('PayPal Order Created', ['paypalOrder' => $paypalOrder]);

        if (!isset($paypalOrder['id'])) {
            \Log::error('PayPal Order Creation Failed', ['paypalOrder' => $paypalOrder]);
            return $this->paymentFailed('PayPal Order Creation Failed.');
        }

        // Return the redirect URL in the response
        return response()->json(['redirect_url' => $paypalOrder['links'][1]['href']]);
    } catch (\Exception $e) {
        \Log::error('Exception in createPayment', ['error' => $e->getMessage()]);
        return $this->paymentFailed($e->getMessage());
    }
}


public function paypalsuccess(Request $request)
{
    $paymentId = $request->query('token'); // Capture token from query parameters
    $payerId = $request->query('PayerID'); // Capture Payer ID from query parameters
    $totalAmount = $this->calculateAmount(); // Amount in cents

    \Log::info('PayPal Success Handler Called', [
        'paymentId' => $paymentId, 
        'payerId' => $payerId,
    ]);

    if (!$paymentId) {
        \Log::error('Invalid Payment ID', ['paymentId' => $paymentId]);
        return redirect()->route('paypal.cancel')->with('error', 'Invalid payment ID.');
    }

    try {
        // Capture the payment
        $payment = $this->paypal->capturePaymentOrder($paymentId);

        \Log::info('PayPal Payment Captured', ['payment' => $payment]);

        if ($payment['status'] === 'COMPLETED') {
            // Store the order with the retrieved payment information
            $order = $this->storeOrder($request, 'paypal', $totalAmount, 'completed');

            if (!$order) {
                \Log::error('Order Creation Failed', ['payment' => $payment]);
                return $this->paymentFailed('Order could not be created.');
            }

            \Log::info('Order Created Successfully', ['order' => $order]);

            // Clear the cart
            $this->clearUserCart();
            \Log::info('Cart Cleared');


                     return redirect()->to(config('app.frontend_url') . '/success/' . $order->id);

                    } else {
            \Log::error('PayPal Payment Capture Failed', ['payment' => $payment]);
            return $this->paymentFailed('PayPal Payment Capture Failed.');
        }
    } catch (\Exception $e) {
        \Log::error('Exception in paypalsuccess', ['error' => $e->getMessage()]);
        return $this->paymentFailed($e->getMessage());
    }
}




  public function success($orderId)
{
    $order = Order::find($orderId);

    if (!$order) {
        return redirect()->route('home')->with('error', 'Order not found.');
    }

    return view('success', ['order' => $order]);
}


    public function cancel()
    {
        return view('cancel');
    }


   



   private function calculateAmountInCents()
{
    $userId = Auth::id();

    // Fetch the cart for the logged-in user and get the related items
    $cart = Cart::where('user_id', $userId)->with('cartItems')->first();

    // Ensure the cart and items are not null
    if (!$cart || $cart->cartItems->isEmpty()) {
        \Log::error('No cart or items found for the user.', ['userId' => $userId]);
        return 0;
    }

    // Calculate the total amount in MAD from cart items
    $totalAmountInDollars = $cart->cartItems->reduce(function ($carry, $item) {
        return $carry + ($item->price * $item->quantity); // Assuming 'price' is in MAD
    }, 0);

    return (int)($totalAmountInDollars * 100); // Convert to cents
}

private function calculateAmount()
{
    $userId = Auth::id();

    // Fetch the cart for the logged-in user and get the related items
    $cart = Cart::where('user_id', $userId)->with('cartItems')->first();

    // Ensure the cart and items are not null
    if (!$cart || $cart->cartItems->isEmpty()) {
        \Log::error('No cart or items found for the user.', ['userId' => $userId]);
        return 0;
    }

    // Calculate the total amount in MAD from cart items
    return $cart->cartItems->reduce(function ($carry, $item) {
        return $carry + ($item->price * $item->quantity); // Assuming 'price' is in MAD
    }, 0);
}


public function storeOrder(Request $request, $transaction, $amount, $status)
{
    try {
        // Validate request data
        $userId = $request->user()->id;
        if (!$userId) {
            Log::error('Store Order Error: User ID is missing.');
            return response()->json(['error' => 'User ID is missing.'], 400);
        }

        Log::info('Transaction value:', ['transaction' => $transaction]);

        // Check the type of $transaction
        if (!is_string($transaction)) {
            Log::error('Store Order Error: Transaction is not a string.', ['transaction' => $transaction]);
            return response()->json(['error' => 'Invalid payment method. Transaction is not a string.'], 400);
        }

        // Start a transaction for safe order creation
        DB::beginTransaction();

        // Create the order
        $order = Order::create([
            'user_id' => $userId,
            'total_amount' => $amount, // Assuming $amount is in cents, convert it to dollars/MAD if needed
            'payment_method' => $transaction, // Assuming transaction contains payment method ID
            'status' => $status,
        ]);

        // Log the created order
        Log::info('Order Created:', ['order' => $order]);

        // Retrieve cart items from session
        $cart = Cart::where('user_id', $userId)->with('cartItems')->first();

        if (!$cart || $cart->cartItems->isEmpty()) {
            Log::error('Store Order Error: Cart is empty.');
            return response()->json(['error' => 'Cart is empty.'], 400);
        }

        // Loop through each cart item and create order items
        foreach ($cart->cartItems as $item) {
            $product = Product::find($item->product_id); // Assuming each cart item has a 'product_id'

            if (!$product) {
                Log::error('Store Order Error: Product not found.', ['product_id' => $item->product_id]);
                return response()->json(['error' => 'Product not found.'], 404);
            }

            // Create order item
            $order->orderItems()->create([
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'price' => $product->price, // Use product's price for order item
            ]);
        }

        // Commit the transaction
        DB::commit();

        // Return the order object directly
        return $order;

    } catch (\Exception $e) {
        // Rollback in case of error
        DB::rollBack();

        // Log any exceptions
        Log::error('Store Order Error:', ['error' => $e->getMessage()]);
        return response()->json(['error' => 'An error occurred while processing the order.'], 500);
    }
}



  private function paymentFailed($message)
{
    Log::error('Payment Error: ' . $message);
    return response()->json(['error' => 'Payment failed. Please try again.'], 400);
}


private function clearUserCart()
{
    $userId = Auth::id();

    // Fetch the user's cart
    $cart = Cart::where('user_id', $userId)->first();

    if ($cart) {
        // Delete all cart items for this user
        $cart->cartItems()->delete();

        // Optionally, delete the cart itself if you no longer need it
        $cart->delete();
        
        \Log::info('User cart cleared.', ['userId' => $userId]);
    } else {
        \Log::warning('No cart found for user when attempting to clear.', ['userId' => $userId]);
    }
}

public function handleCashOnDelivery(Request $request)
{
    // Log the request for debugging
    \Log::info('Cash on Delivery requested', ['user_id' => Auth::id()]);

    // Calculate the total amount
    $totalAmount = $this->calculateAmount();

    // Store the order with 'pending' status
    $order = $this->storeOrder($request, 'cash_on_delivery', $totalAmount, 'pending');

    // Check if the order is stored successfully
    if (!$order) {
        \Log::error('Order could not be stored.');
        return $this->paymentFailed('Order could not be created.');
    }

    // Clear the user's cart
    $this->clearUserCart();

    \Log::info('Order created and cart cleared', ['order_id' => $order]);

    // Redirect to success page
     return response()->json( ['orderId' => $order->id]);
}





}