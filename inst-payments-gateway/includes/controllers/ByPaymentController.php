<?php

use By\Gateways\By_Gateway;

class ByPaymentController {

    /**
     * @throws Exception
     */
    public function payment($gateway, $payType) {
        $orderId = (int)WC()->session->get('beyounger_order');
//        echo $orderId . "\n";

        $order = wc_get_order($orderId);
        if (empty($order)) {
            throw new Exception('Order not found: ' . $orderId);
        }

        $sdk = new HttpUtil();
        $url = $gateway->domain . '';
        $key = $gateway->api_key . '';
        $secret = $gateway->api_secret . '';  
        $api_webhook = $gateway->api_webhook . '?wc-api=by_webhook'; //http://127.0.0.1/?wc-api=by_webhook

        $customer = array(
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'country' => $order->get_billing_country(),
            'state' => $order->get_billing_state(),
            'city' => $order->get_billing_city(),
            'address' => $order->get_billing_address_1() . $order->get_billing_address_2(),
            'zipcode' => $order->get_billing_postcode(),
        );

//        // todo 哪里获取商品信息？ https://stackoverflow.com/questions/39401393/how-to-get-woocommerce-order-details
//        $product_info = array(
//            'name' => 'name'
//        );
//
//        $shipping_info = array(
//            'phone' => $order->get_shipping_phone(),
//            'first_name' => $order->get_shipping_first_name(),
//            'last_name' => $order->get_shipping_last_name(),
//            'country' => $order->get_shipping_country(),
//            'state' => $order->get_shipping_state(),
//            'city' => $order->get_shipping_city(),
//            'address' => $order->get_shipping_address_1() . $order->get_shipping_address_2(),
//            'zipcode' => $order->get_shipping_postcode(),
//            'company' => $order->get_shipping_company(),
//        );
        $cart_items = [];
        foreach ($order->get_items() as $item_key => $item ):
            $item_id = $item->get_id();
            //$product      = $item->get_product(); // Get the WC_Product object
            //$product_id   = $item->get_product_id(); // the Product id
            $item_name    = $item->get_name(); // Name of the product
            $quantity     = $item->get_quantity();
            $total        = $item->get_total(); // Line total (discounted)
            //echo $item . "=====\n";
            $myitem = array(
                'id' => $item_id,
                'name' => $item_name,
                'quantity' => $quantity,
                'unitPrice' => array(
                    'currency' => $order->get_currency(),
                    'value' => $total
                ),
            );
            array_unshift($cart_items, $myitem);
        endforeach;

        $home_url = home_url();
        $home_url = rtrim(str_replace('https://','',str_replace('http://','',$home_url)));
        preg_match('@^(?:https://)?([^/]+)@i',str_replace('www.','',$home_url), $matches);
        $memo = $matches[1] . '-' . $order->get_id();
        //echo '拼接memo:' . $memo ."\n";


        $post_data = array(
            'currency' => $order->get_currency(),
            'amount' => $order->get_total(),
            'cust_order_id' => 'woo' . substr($key, 0 ,5) . date("YmdHis",time()) . $orderId,
            'customer' => $customer,
            'payment_method' => 'creditcard',
            'notification_url' => $api_webhook,
//            'product_info' => $product_info,
//            'shipping_info' => $shipping_info,
            'cart_items' => $cart_items,
            'return_url' => $order->get_view_order_url(),
            'network' => $payType,
            'memo' => $memo,
        );

        //$post_data = $sdk->formatArray($post_data);

        $requestPath = "/api/v1/payment";
        $timeStamp = round(microtime(true) * 1000);
        $signatureData = $key .
        "&" . $post_data['cust_order_id'] .
        "&" . $post_data['amount'] .
        "&" . $post_data['currency'] .
        "&" . $secret .
        "&" . $timeStamp;

        $result = $sdk->post($url, $requestPath, $post_data, $signatureData, $key, $timeStamp);
        //echo $post_data['cust_order_id'] . "\n";
//        $result = '{}';
//        echo $order->get_data() . "=====\n";
//        echo json_encode($order). "=====\n";;
        echo $order->get_id() . "++++++++\n";

        $result = json_decode($result, true);
        if ( $result['code'] === 0 ) {

            // 给客户的一些备注（用false代替true使其变为私有）
            $order->add_order_note( 'Payment is processing on ' . $result['result']['redirect_url'], true );

            // 空购物车
            WC()->cart->empty_cart();

//            echo $result['result']['redirect_url'] . "\n";
            // 重定向，根据配置决定跳转到receipt_page(iframe)还是支付页面
//            echo 'iframe?:' . $gateway->iframe . "\n";
            if ($gateway->iframe === 'yes') {
//                echo 'iframe:' . $result['result']['redirect_url'] . "\n";
                update_post_meta($orderId, 'by_url', $result['result']['redirect_url']);
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true),
                    'payment_url' => $result['result']['redirect_url'],
                );
            } else {
//                echo 'redirect:' . $result['result']['redirect_url'] . "\n";
                return array(
                    'result' => 'success',
                    'redirect' => $result['result']['redirect_url'],
                );
            }
        } else if ($result['code'] === 117008) {
            wc_add_notice('Transaction already exist. Please check in order-view page.', 'error' );
            return array(
                'result' => 'error',
            );
        } else {
            wc_add_notice(  'Please try again.', 'error' );
            return array(
                'result' => 'error',
            );
        }
    }

    public function webhook() { // todo 起一个service去做

        http_response_code(200);
        header('Content-Type: application/json');

//        $gateway = new By_Gateway;
//        $enabled = $gateway->api_webhook;
//        if ($enabled === 'no') {
//            echo json_encode([
//                'code' => 1,
//                'msg'  => 'REFUSE',
//            ]);
//            die;
//        }

        $this->webhook_internal();
    }


    private function webhook_internal() {
        http_response_code(200);
        header('Content-Type: application/json');

        // todo 验签
        $result = true;

        if ($result) { //check succeed
            $tmpData = strval(file_get_contents("php://input"));
            $dataArray = json_decode($tmpData, true);
            if (strcmp($dataArray['action'], 'order_result') == 0) {

                $event = $dataArray['event'];
                $order_id = substr($event['cust_order_id'],22);
                $order = wc_get_order($order_id);
                if (empty($order)) {
                    return;
                }
                $status = $event['status'];
                if ($status == 1) { // 成功
                    //$order->payment_complete();
                    //$order->add_order_note( 'Payment is completed. (By Webhook)', true);
                    $order->update_status('processing', 'processing. (By Webhook)');
                } elseif ($status == 4) { // 失败
                    $order->update_status('failed', 'Failed. (By Webhook)');
                } elseif ($status == 5) { // 取消
                    $order->update_status('cancelled', 'Cancelled. (By Webhook)');
                } elseif ($status == 6) { // 过期
                    $order->update_status('failed', 'Expired. (By Webhook)');
                }

            } // todo 是否需要接收其他推送action？
            echo json_encode([
                'code' => 0,
                'msg'  => 'SUCCESS',
            ]);
            die;
        } else {
            echo json_encode([
                'code' => 3,
                'msg'  => 'VERIFY SIG FAIL',
            ]);
            die;
        }
    }
}
