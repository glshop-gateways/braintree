<?php
/**
 * Gateway implementation for braintree (https://braintree.io).
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2020 Lee Garner <lee@leegarner.com>
 * @package     shop
 * @version     v0.0.1
 * @license     http://opensource.org/licenses/gpl-2.0.php
 *              GNU Public License v2 or later
 * @filesource
 */
namespace Shop\Gateways\braintree;
use Shop\Config;
use Shop\Currency;
use Shop\Logger\IPN as logIPN;
use Shop\Models\IPN as modelIPN;
use Shop\Models\OrderState;
use Shop\Payment;
use LGLib\NameParser;


/**
 * Class for braintree payment gateway.
 * @package shop
 */
class Gateway extends \Shop\Gateway
{
    /** Gateway version.
     * @const string */
    protected const VERSION = '0.0.1';

    /** Gateway ID.
     * @var string */
    protected $gw_name = 'braintree';

    /** Gateway provider. Company name, etc.
     * @var string */
    protected $gw_provider = 'Braintree';

    /** Gateway service description.
     * @var string */
    protected $gw_desc = 'Braintree Payment Gateway';

    /** Internal API client to facilitate reuse.
     * @var object */
    private $_api_client = NULL;

    /** IPN model object.
     * @var object */
    private $IPN = NULL;


    /**
     * Constructor.
     * Set gateway-specific items and call the parent constructor.
     *
     * @param   array   $A      Array of fields from the DB
     */
    public function __construct($A=array())
    {
        // Set up config field definitions.
        $this->cfgFields = array(
            'prod' => array(
                'merchant_id'   => 'string',
                'merchant_account_id' => 'string',
                'public_key'    => 'password',
                'private_key'   => 'password',
            ),
            'test' => array(
                'merchant_id'   => 'string',
                'merchant_account_id' => 'string',
                'public_key'    => 'password',
                'private_key'   => 'password',
            ),
            'global' => array(
                'test_mode' => 'checkbox',
            ),
        );
        // Set config defaults
        $this->config = array(
            'global' => array(
                'test_mode'         => '1',
            ),
        );

        // Set the only service supported
        $this->services = array('checkout' => 1, 'terms' => 0);

        // Call the parent constructor to initialize the common variables.
        parent::__construct($A);
    }


    /**
     * Get the main gateway url.
     * This is used to tell the buyer where they can log in to check their
     * purchase. For PayPal this is the same as the production action URL.
     *
     * @return  string      Gateway's home page
     */
    public function getMainUrl()
    {
        return '';
    }


    /**
     * Get the form variables for the purchase button.
     *
     * @uses    Gateway::Supports()
     * @uses    _encButton()
     * @uses    getActionUrl()
     * @param   object  $Cart   Shopping cart object
     * @return  string      HTML for purchase button
     */
    public function checkoutButton($Cart, $text='')
    {
        global $LANG_SHOP;

        if (!$this->Supports('checkout')) {
            return '';
        }
        static $have_js = false;

        $clientToken = $this->_getApiClient()->clientToken()->generate(array(
//            'customerId' => $Cart->getUid()
        ) );
        $cartID = $Cart->getOrderId();
        $shipping = 0;
        $Cur = Currency::getInstance();

        if (!$have_js) {
            $outputHandle = \outputHandler::getInstance();
            $outputHandle->addLinkScript('//sdk.braintree.io/3.js');
            $outputHandle->addLinkScript('https://js.braintreegateway.com/web/dropin/1.25.0/js/dropin.min.js');
            $have_js = true;
        }

        $T = new \Template(__DIR__ . '/templates');
        $T->set_file('js', 'checkout.thtml');
        $T->set_var(array(
            'action_url' => $this->getActionUrl(),
            'method'    => $this->getMethod(),
            'button_url' => $this->getCheckoutButton(),
            'disabled'  => $Cart->hasInvalid(),
            'uniqid'    => uniqid(),
            //'btn_text'  => $text != '' ? $text : $LANG_SHOP['confirm_order'],
            'amount'    => $Cart->getBalanceDue(),
            'client_token' => $clientToken,
            'order_id'  => $Cart->getOrderId(),
        ) );

        $T->parse('output', 'js');
        $btn = $T->finish($T->get_var('output'));
        return $btn;
    }


    /**
     * Get the values to show in the "Thank You" message when a customer
     * returns to our site.
     *
     * @uses    getMainUrl()
     * @uses    Gateway::getDscp()
     * @return  array       Array of name=>value pairs
     */
    public function thanksVars()
    {
        $R = array(
            'gateway_url'   => self::getMainUrl(),
            'gateway_name'  => self::getDscp(),
        );
        return $R;
    }


    /**
     * Get the variables to display with the IPN log.
     * This gateway does not have any particular log values of interest.
     *
     * @param  array   $data       Array of original IPN data
     * @return array               Name=>Value array of data for display
     */
    public function ipnlogVars($data)
    {
        return array();
    }


    /**
     * Get the form method to use with the final checkout button.
     * Return POST by default
     *
     * @return  string  Form method
     */
    public function getMethod()
    {
        return 'post';
    }


    /**
     * Make the API classes available. May be needed for reports.
     *
     * @return  object  $this
     */
    public function loadSDK()
    {
        require_once __DIR__ . '/vendor/autoload.php';
        return $this;
    }


    /**
     * Get the API client object.
     *
     * @return  object      braintree API object
     */
    private function _getApiClient()
    {
        if ($this->_api_client === NULL) {
            // Import the API SDK
            $this->loadSDK();
            $this->_api_client = new \Braintree\Gateway(
                array(
                    'environment' => $this->getConfig('test_mode') ? 'sandbox' : 'production',
                    'merchantId' => $this->getConfig('merchant_id'),
                    'publicKey' => $this->getConfig('public_key'),
                    'privateKey' => $this->getConfig('private_key'),
                )
            );
        }
        return $this->_api_client;
    }


    /**
     * Get the transaction data using the ID supplied in the IPN.
     *
     * @param   string  $trans_id   Transaction ID from IPN
     * @return  array   Array of transaction data.
     */
    public function getTransaction($trans_id)
    {
        if (empty($trans_id)) {
            return false;
        }
        $transactions = $this->_getApiClient()->transactions();
        $transaction  = $transactions->fetch($trans_id);
        return $transaction;
    }


    /**
     * Capture the transaction amount.
     *
     * @param   string  $trans_id   Transaction ID
     * @param   array   $args       Arguments, amount and currency
     * @return  boolean     True on success, False on error
     */
    public function captureTransaction($trans_id, $args)
    {
        if (empty($trans_id)) {
            return false;
        }
        if (!isset($args['amount']) || $args['amount'] < .01) {
            return false;
        }
        $transactions = $this->_getApiClient()->transactions();
        $txn = $transactions->capture($trans_id, $args);
        $captured = SHOP_getVar($txn, 'capturedAmount', 'integer');
        if ($captured == $args['amount']) {
            return true;
        } else {
            return false;
        }
     }


    /**
     * Get additional javascript to be attached to the checkout button.
     *
     * @param   object  $Cart   Shopping cart object
     * @return  string      Javascript commands.
     */
    public function getCheckoutJS($Cart)
    {
        //return 'SHOP_braintree_' . $Cart->getOrderId() . '(); return false;';
        return '';
    }


    /**
     * Check that a valid config has been set for the environment.
     *
     * @return  boolean     True if valid, False if not
     */
    public function hasValidConfig()
    {
        return !empty($this->getConfig('public_key')) &&
            !empty($this->getConfig('private_key')) &&
            !empty($this->getConfig('merchant_id'));
    }


    public function getActionUrl()
    {
        return Config::get('url') . '/confirm.php';
    }



    private function _processOrder($Order)
    {
        $ipn = new logIPN();
        $ipn->setOrderID($Order->getOrderId())
            ->setTxnID($this->IPN['id'])
            ->setGateway($this->gw_name)
            ->setEvent($this->IPN['event'])
            ->setVerified(1)
            ->setData($this->IPN->toArray());
        $ipn->Write();

        // Get the payment by reference ID to make sure it's unique
        $Pmt = Payment::getByReference($this->IPN['id']);
        if ($Pmt->getPmtID() == 0) {
            $Pmt->setRefID($this->IPN['id'])
                ->setAmount($this->IPN['pmt_gross'])
                ->setGateway($this->gw_name)
                ->setStatus($this->IPN['data']['processorResponseType'])
                ->setMethod($this->IPN['data']['paymentInstrumentType'])
                ->setComment($this->IPN['data']['paymentInstrumentType'] . ' ' . $this->IPN['id'])
                ->setOrderID($Order->getOrderID());
            $Pmt->Save();
            foreach ($Order->getItems() as $Item) {
                $Item->getProduct()->handlePurchase($Item, $this->IPN);
            }
            if ($Order->hasPhysical()) {
                $Order->updateStatus(OrderState::PROCESSING);
            } else {
                $Order->updateStatus(OrderState::SHIPPED);
            }
            return true;
        }
        return false;
    }


    public function confirmOrder($Order)
    {
        global $LANG_SHOP, $_CONF;

        $amount = $Order->getBalanceDue();
        $nonce = $_POST["payment_method_nonce"];

        $params = [
            'amount' => $amount,
            'orderId' => $Order->getOrderId(),
            'paymentMethodNonce' => $nonce,
            'customer' => [
                'firstName' => NameParser::F($Order->getBillto()->getName()),
                'lastName' => NameParser::L($Order->getBillto()->getName()),
                'company' => $Order->getBillto()->getCompany(),
                'phone' => $Order->getBillto()->getPhone(),
                'email' => $Order->getBuyerEmail(),
            ],
            'billing' => [
                'firstName' => NameParser::F($Order->getBillto()->getName()),
                'lastName' => NameParser::L($Order->getBillto()->getName()),
                'company' => $Order->getBillto()->getCompany(),
                'streetAddress' => $Order->getBillto()->getAddress1(),
                'extendedAddress' => $Order->getBillto()->getAddress2(),
                'locality' => $Order->getBillto()->getCity(),
                'region' => $Order->getBillto()->getState(),
                'postalCode' => $Order->getBillto()->getPostal(),
                'countryCodeAlpha2' => $Order->getBillto()->getCountry(),
            ],
            'shipping' => [
                'firstName' => NameParser::F($Order->getShipto()->getName()),
                'lastName' => NameParser::L($Order->getShipto()->getName()),
                'company' => $Order->getShipto()->getCompany(),
                'streetAddress' => $Order->getShipto()->getAddress1(),
                'extendedAddress' => $Order->getShipto()->getAddress2(),
                'locality' => $Order->getShipto()->getCity(),
                'region' => $Order->getShipto()->getState(),
                'postalCode' => $Order->getShipto()->getPostal(),
                'countryCodeAlpha2' => $Order->getShipto()->getCountry(),
            ],
            'options' => [
                'submitForSettlement' => true
            ]
        ];
        if (!empty($this->getConfig('merchant_account_id'))) {
            $params['merchantAccountId'] = $this->getConfig('merchant_account_id');
        }
        $result = $this->_getApiClient()->transaction()->sale($params);

        if ($result->success || !is_null($result->transaction)) {
            $this->IPN = new modelIPN;
            $this->IPN['sql_date'] = $_CONF['_now']->toMySQL(true);
            $this->IPN['uid'] = $Order->getUid();
            $this->IPN['pmt_gross'] = $result->transaction->amount;
            $this->IPN['txn_id'] = $result->transaction->id;
            $this->IPN['event'] = $result->transaction->type;
            $this->IPN['gw_name'] = $this->gw_name;
            $this->IPN['custom'] = array(
                'uid' => $Order->getUid(),
            );
            $this->IPN['data'] = array(
                'id' => $result->transaction->id,
                'order_id' => $result->transaction->orderId,
                'type' => $result->transaction->type,
                'amount' => $result->transaction->amount,
                'status' => $result->transaction->status,
                'merchantAccountId' => $result->transaction->merchantAccountId,
                'customer' => $result->transaction->customer,
                'billing' => $result->transaction->billing,
                'shipping' => $result->transaction->shipping,
                'avsErrorResponseCode' => $result->transaction->avsErrorResponseCode,
                'avsPostalCodeResponseCode' => $result->transaction->avsPostalCodeResponseCode,
                'avsStreetAddressResponseCode' => $result->transaction->avsStreetAddressResponseCode,
                'cvvResponseCode' => $result->transaction->cvvResponseCode,
                'gatewayRejectionReason' => $result->transaction->gatewayRejectionReason,
                'processorAuthorizationCode' => $result->transaction->processorAuthorizationCode,
                'processorResponseCode' => $result->transaction->processorResponseCode,
                'processorResponseType' => $result->transaction->processorResponseType,
                'processorResponseText' => $result->transaction->processorResponseText,
                'paymentInstrumentType' => $result->transaction->paymentInstrumentType,
                'networkTransactionId' => $result->transaction->networkTransactionId,
                'networkTransactionId' => $result->transaction->networkTransactionId,
                'globalId' => $result->transaction->globalId,
            );
            //var_dump($this->IPN);die;
            if ($result->transaction->amount >= $amount) {
                if ($this->_processOrder($Order)) {
                    COM_refresh(SHOP_URL . '/index.php?thanks=' . $this->gw_name);
                }
            }
        } else {
            $errorString = "";
            foreach($result->errors->deepAll() as $error) {
                SHOP_log('Braintree Error: ' . $error->code . ": " . $error->message);
            }
        }
        COM_setMsg($LANG_SHOP['pmt_error']);
        COM_refresh(SHOP_URL . '/index.php');
    }

}
