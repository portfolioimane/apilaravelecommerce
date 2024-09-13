<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Frontend\ProductController;
use App\Http\Controllers\Api\Frontend\CartController;
use App\Http\Controllers\Api\Frontend\AuthController;
use App\Http\Controllers\Api\Frontend\CheckoutController;
use App\Http\Controllers\Api\Frontend\OrderController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::get('/latest-products', [ProductController::class, 'latestProducts']);
Route::get('/product/{id}', [ProductController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

// Protected routes for authenticated users
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/cart/add', [CartController::class, 'addToCart']);
   Route::get('/cart/count', [CartController::class, 'getCartItemCount']);
    Route::get('/user', [AuthController::class, 'getUser']);
    Route::get('/cart', [CartController::class, 'showCart']);
    Route::delete('/cart/remove/{id}', [CartController::class, 'removeFromCart']);

// Route to initiate the checkout process (usually returns some information, not a view)
Route::get('/checkout', [CheckoutController::class, 'checkout']);

// Route to process payment
Route::post('/process-payment', [CheckoutController::class, 'processPayment']);

// Route to handle payment return (could be used for redirect handling or status updates)
Route::get('/payment-return', [CheckoutController::class, 'handlePaymentReturn']);


// Route to handle cancellation of payment


// Route for Cash on Delivery (COD)
Route::post('/checkout/cash-on-delivery', [CheckoutController::class, 'handleCashOnDelivery']);

Route::get('/order-details/{orderId}', [OrderController::class, 'getOrderDetails']);
Route::post('/create-payment', [CheckoutController::class, 'createPayment']);
Route::get('/paypalsuccess', [CheckoutController::class, 'paypalsuccess'])->name('paypal.success');
Route::get('/cancel', [CheckoutController::class, 'cancel'])->name('paypal.cancel');
Route::get('/paypalsuccess', [CheckoutController::class, 'paypalsuccess']);
Route::get('/cancel', [CheckoutController::class, 'cancel']);


});







