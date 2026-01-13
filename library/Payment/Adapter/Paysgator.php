<?php
/**
 * Paysgator Payment Gateway Adapter for FOSSBilling
 * 
 * This adapter integrates Paysgator payment gateway with FOSSBilling.
 * It supports webhook notifications and uses API Key authentication.
 * 
 * @copyright 2025 Paysgator
 * @license Apache-2.0
 */

class Payment_Adapter_Paysgator extends Payment_AdapterAbstract
{
    /**
     * Return payment gateway configuration form fields
     * 
     * @return array
     */
    public function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept payments via Paysgator - M-Pesa, E-mola, Cards and more',
            'logo' => array(
                'logo' => 'Paysgator.png',
                'height' => '30px',
                'width' => '100px',
            ),
            'form' => array(
                'api_key' => array(
                    'text', 
                    array(
                        'label' => 'API Key',
                        'description' => 'Enter your Paysgator API Key (Live or Test)',
                        'validators' => array('nonempty'),
                    ),
                ),
                'webhook_secret' => array(
                    'text',
                    array(
                        'label' => 'Webhook Secret',
                        'description' => 'Optional: Enter your Paysgator Webhook Secret for signature verification',
                    ),
                ),
                'test_mode' => array(
                    'radio',
                    array(
                        'multiOptions' => array('1' => 'Yes', '0' => 'No'),
                        'label' => 'Test Mode',
                        'description' => 'Enable test mode for sandbox testing',
                    ),
                ),
            ),
        );
    }

    /**
     * Return payment gateway type
     * 
     * @return string
     */
    public function getType()
    {
        return Payment_AdapterAbstract::TYPE_FORM;
    }

    /**
     * Return payment service URL
     * 
     * @param Payment_Invoice $invoice
     * @return string
     */
    public function getServiceUrl(Payment_Invoice $invoice)
    {
        return 'https://paysgator.com/api/v1/payment/create';
    }

    /**
     * Process payment - create payment link and redirect
     * 
     * @param Payment_Invoice $invoice
     * @return Payment_Transaction
     */
    public function process(Payment_Invoice $invoice)
    {
        $config = $this->getConfig();
        
        // Generate sanitized external transaction ID with timestamp (max 15 chars)
        // Format: {invoiceId}inv{timestamp} truncated to 15 chars
        $externalTxId = $invoice->getId() . 'inv' . time();
        $externalTxId = preg_replace('/[^a-zA-Z0-9_-]/', '', $externalTxId);
        $externalTxId = substr($externalTxId, 0, 15);
        
        // Prepare API request
        $data = array(
            'amount' => (double)$invoice->getTotalWithTax(),
            'currency' => $invoice->getCurrency(),
            'externalTransactionId' => $externalTxId,
            'fields' => array('name', 'email', 'phone', 'address'),
            'returnUrl' => $this->getParam('return_url'),
            'metadata' => array(
                'description' => 'Invoice #' . $invoice->getNumber(),
                'source' => 'FOSSBilling',
                'invoice_id' => $invoice->getId(),
                'client_email' => $invoice->getBuyer()->getEmail(),
            ),
        );
        
        // Make API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getServiceUrl($invoice));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->getParam('api_key'),
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Payment_Exception('Payment Error: ' . $curlError);
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200 || !isset($result['success']) || !$result['success']) {
            $errorMsg = isset($result['error']['message']) ? $result['error']['message'] : 'Payment creation failed';
            throw new Payment_Exception('Payment Error: ' . $errorMsg);
        }
        
        // Create transaction record
        $tx = new Payment_Transaction();
        $tx->setId($result['data']['transactionId']);
        $tx->setAmount($invoice->getTotalWithTax());
        $tx->setCurrency($invoice->getCurrency());
        $tx->setType(Payment_Transaction::TXTYPE_PAYMENT);
        $tx->setStatus(Payment_Transaction::STATUS_PENDING);
        
        // Redirect to checkout URL
        header('Location: ' . $result['data']['checkoutUrl']);
        exit;
    }

    /**
     * Process IPN (Instant Payment Notification) / Webhook
     * 
     * @param Payment_Transaction $tx
     * @param array $data
     * @param Payment_Invoice $invoice
     * @return Payment_Transaction
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        // Get raw POST body
        $rawPayload = file_get_contents('php://input');
        $webhookData = json_decode($rawPayload, true);
        
        // Verify signature if webhook secret is configured
        $signature = isset($_SERVER['HTTP_X_PAYSGATOR_SIGNATURE']) ? $_SERVER['HTTP_X_PAYSGATOR_SIGNATURE'] : '';
        $webhookSecret = $this->getParam('webhook_secret');
        
        if (!empty($webhookSecret)) {
            $expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
            if (!hash_equals($expectedSignature, $signature)) {
                throw new Payment_Exception('Invalid webhook signature');
            }
        }
        
        // Validate webhook structure
        if (!isset($webhookData['event']) || !isset($webhookData['data'])) {
            throw new Payment_Exception('Invalid webhook structure');
        }
        
        $event = $webhookData['event'];
        $eventData = $webhookData['data'];
        
        // Only process payment.success events
        if ($event !== 'payment.success') {
            return null;
        }
        
        // Extract payment data
        $transactionId = isset($eventData['transactionId']) ? $eventData['transactionId'] : null;
        $amount = isset($eventData['amount']) ? $eventData['amount'] : 0;
        $status = isset($eventData['status']) ? $eventData['status'] : '';
        $externalTransactionId = isset($eventData['externalTransactionId']) ? $eventData['externalTransactionId'] : null;
        
        if (!$externalTransactionId) {
            throw new Payment_Exception('No externalTransactionId found');
        }
        
        // Parse invoice ID from externalTransactionId (format: {invoiceId}inv{timestamp})
        // Extract the part before 'inv'
        $parts = explode('inv', $externalTransactionId);
        $invoiceId = isset($parts[0]) ? $parts[0] : '';
        $invoiceId = preg_replace('/[^0-9]/', '', $invoiceId);
        
        if (!$invoiceId) {
            throw new Payment_Exception('Invalid externalTransactionId format');
        }
        
        // Verify status is SUCCESS
        if ($status !== 'SUCCESS') {
            return null;
        }
        
        // Update transaction
        $tx = $api_admin->invoice_transaction_get(array('id' => $id));
        $bd = array(
            'id' => $id,
            'invoice_id' => $invoiceId,
            'txn_id' => $transactionId,
            'amount' => $amount,
            'status' => 'processed',
        );
        
        $api_admin->invoice_transaction_update($bd);
        
        return true;
    }
}
