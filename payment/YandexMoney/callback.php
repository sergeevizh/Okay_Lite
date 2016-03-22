<?php

// Работаем в корневой директории
chdir ('../../');
require_once('api/Okay.php');

$okay = new Okay();

$order_id = $okay->request->post('customerNumber', 'integer');
$invoice_id = $okay->request->post('invoiceId', 'string');

////////////////////////////////////////////////
// Выберем заказ из базы
////////////////////////////////////////////////
$order = $okay->orders->get_order(intval($order_id));
if(empty($order))
	print_error('Оплачиваемый заказ не найден');
 
////////////////////////////////////////////////
// Выбираем из базы соответствующий метод оплаты
////////////////////////////////////////////////
$method = $okay->payment->get_payment_method(intval($order->payment_method_id));
if(empty($method))
	print_error("Неизвестный метод оплаты");
 
$settings = unserialize($method->settings);
$shop_id = $settings['yandex_shopid'];
       
// Нельзя оплатить уже оплаченный заказ  
if($order->paid)
	print_error('Этот заказ уже оплачен');
       
////////////////////////////////////
// Проверка контрольной подписи
////////////////////////////////////
$str =	$_POST['action'].';'.$_POST['orderSumAmount'].';'.
		$_POST['orderSumCurrencyPaycash'].';'.$_POST['orderSumBankPaycash'].';'.
		$settings['yandex_shopid'].';'.$invoice_id.';'.
		$_POST['customerNumber'].';'.$settings['yandex_password'];

$md5 = strtoupper(md5($str));
if($md5 !== $_POST['md5'])
		print_error("Контрольная подпись не верна");

////////////////////////////////////
// Проверка суммы платежа
////////////////////////////////////
       
// Сумма заказа у нас в магазине
$order_amount = $okay->money->convert($order->total_price, $method->currency_id, false);
       
// Должна быть равна переданной сумме
if(floatval($order_amount) !== floatval($_POST['orderSumAmount']))
	print_error("Неверная сумма оплаты");

////////////////////////////////////
// Проверка наличия товара
////////////////////////////////////
$purchases = $okay->orders->get_purchases(array('order_id'=>intval($order->id)));
foreach($purchases as $purchase)
{
	$variant = $okay->variants->get_variant(intval($purchase->variant_id));
	if(empty($variant) || (!$variant->infinity && $variant->stock < $purchase->amount))
	{
		print_error("Нехватка товара $purchase->product_name $purchase->variant_name");
	}
}
       
// Запишем
if($_POST['action'] == 'paymentAviso')
{
	// Установим статус оплачен
	$okay->orders->update_order(intval($order->id), array('paid'=>1));

	// Спишем товары  
	$okay->orders->close(intval($order->id));
	$okay->notify->email_order_user(intval($order->id));
	$okay->notify->email_order_admin(intval($order->id));
	
	$datetime = new DateTime();
	$performedDatetime = $datetime->format('c');
	print '<?xml version="1.0" encoding="UTF-8"?> 
	<paymentAvisoResponse performedDatetime="'.$performedDatetime.'" 
	code="0" invoiceId="'.$invoice_id.'" 
	shopId="'.$shop_id.'"/>';
	
}
elseif($_POST['action'] == 'checkOrder')
{
	$datetime = new DateTime();
	$performedDatetime = $datetime->format('c');
	print '<?xml version="1.0" encoding="UTF-8"?> 
	<checkOrderResponse performedDatetime="'.$performedDatetime.'" 
	code="0" invoiceId="'.$invoice_id.'" 
	shopId="'.$shop_id.'"/>';
}

function print_error($text)
{
	$datetime = new DateTime();
	$performedDatetime = $datetime->format('c');
	$shop_id = intval($_POST['shopId']);
	$invoice_id = intval($_POST['invoiceId']);
	
	$responce = '';
	$action = $_POST['action'];
	if($action === 'paymentAviso')
		$responce = 'paymentAvisoResponse';
	elseif($action === 'checkOrder')
		$responce = 'checkOrderResponse';
	

	print '<?xml version="1.0" encoding="UTF-8"?> 
	<'.$responce.' performedDatetime="'.$performedDatetime.'" 
	code="200" invoiceId="'.$invoice_id.'" 
	message="'.$text.'" shopId="'.$shop_id.'"/>';

	exit();
}