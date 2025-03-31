<?php
/*
 * @package    Atom-S ReConnect
 * @version    __DEPLOY_VERSION__
 * @author     Atom-S - atom-s.com
 * @copyright  Copyright (c) 2017 - 2024 Atom-S LLC. All rights reserved.
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link       https://atom-s.com
 */

namespace Joomla\Plugin\Jatoms\Tinkoff\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\DispatcherInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Jatoms\Administrator\Helper\LogHelper;
use Joomla\Component\Jatoms\Administrator\Helper\OrderHelper;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;

class Tinkoff extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Loads the application object.
	 *
	 * @var  \Joomla\CMS\Application\CMSApplication
	 *
	 * @since  1.0.0
	 */
	protected $app = null;

	/**
	 * Constructor.
	 *
	 * @param   DispatcherInterface  &$subject  The object to observe.
	 * @param   array                 $config   An optional associative array of configuration settings.
	 *
	 * @since  1.0.0
	 */
	public function __construct(&$subject, $config = [])
	{
		parent::__construct($subject, $config);

		LogHelper::startDebug('plg_jatoms_tinkoff');
	}

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAtomsPaymentPay'     => 'onAtomsPaymentPay',
			'onAtomsPaymentConfirm' => 'onAtomsPaymentConfirm',
			'onAtomsPaymentError'   => 'onAtomsPaymentError',
			'onAtomsPaymentSuccess' => 'onAtomsPaymentSuccess'
		];
	}

	/**
	 * Method to create order.
	 *
	 * @param   object    $order       Order data object.
	 * @param   object    $tour        Tour data object.
	 * @param   Registry  $params      Atom-S Connect component params.
	 * @param   array     $links       Atom-S Connect plugin links.
	 * @param   bool      $prepayment  Prepayment flag
	 *
	 * @return  array|false  Payment redirect data on success, False on failure.
	 *
	 * @throws  \Exception
	 *
	 * @since  1.0.0
	 */
	public function onAtomsPaymentPay($order, $tour, $links, $params, $prepayment)
	{
		$result = array(
			'pay_instant' => ($params->get('tinkoff_pay_instant', 1)),
		);

		// Prepare links
		$OrderId = $order->order->id . '_' . Factory::getDate()->toUnix();

		// Prepare data
		$input   = Factory::getApplication()->getInput();
		$amount  = (float) $input->get('cost', '', 'float') * 100;
		$name    = OrderHelper::generateProductLabel($order, $tour);
		$product = array(
			'Name'            => $name,
			'Price'           => $amount,
			'Quantity'        => 1.00,
			'Amount'          => $amount,
			'PaymentMethod'   => $params->get('tinkoff_kassa_method', 'full_prepayment'),
			'PaymentObject'   => $params->get('tinkoff_kassa_object', 'service'),
			'Tax'             => $params->get('tinkoff_vat'),
			'MeasurementUnit' => "шт",
			'ShopCode'        => $tour->id,
		);

		// Check prepayment
		if ($prepayment)
		{
			$product['PaymentMethod'] = 'prepayment';
			$product['PaymentObject'] = 'payment';
		}

		$receipt = array(
			'Taxation' => $params->get('tinkoff_taxation', 'osn'),
			'Payments' => array('Electronic' => $amount),
			'Items'    => array($product),
		);

		$extraData = array(
			'prepayment'  => $prepayment,
			'used_ops_id' => $input->get('used_ops_id'),
			'identifier'  => $input->get('identifier'),
			'invoice_id'  => $input->get('invoice_id'),
		);

		$data = array(
			'TerminalKey'     => $params->get('tinkoff_terminal_key'),
			'Amount'          => $amount,
			'Description'     => $name,
			'OrderId'         => $OrderId,
			'NotificationURL' => $links['confirm'] . '?' . http_build_query($extraData),
			'DATA'            => $extraData
		);

		if (!empty($order->user))
		{
			if (!empty($order->user->email))
			{
				$data['DATA']['Email'] = $order->user->email;
				$receipt['Email']      = $order->user->email;
			}

			if (!empty($order->user->phone))
			{
				$data['DATA']['Phone'] = $order->user->phone;
				$receipt['Phone']      = $order->user->phone;
			}
		}

		$data['Receipt'] = $receipt;

		// Debug
		LogHelper::addDebug('plg_jatoms_tinkoff', 'Register order data', 'Data', $data);

		//Create transaction request
		$request = $this->sendRequest('/Init', $data, $params);

		$result['link'] = $request->get('PaymentURL');

		return $result;
	}

	/**
	 * Method to get payment confirm data.
	 *
	 * @param   array     $input   The input data array.
	 * @param   Registry  $params  Atom-S Connect component params.
	 *
	 * @return  array|false  Create Atom-S payment confirm data on success, false on failure.
	 *
	 * @throws  \Exception
	 *
	 * @since  1.0.0
	 */
	public function onAtomsPaymentConfirm($input, $params)
	{
		// Debug
		LogHelper::addDebug('plg_jatoms_tinkoff', 'Confirm input data', 'Data', $input);

		if (empty($input['Status']) || $input['Status'] !== 'CONFIRMED')
		{
			header('Content-Type: text');
			echo 'OK';
			$this->app->close(200);

			return false;
		}

		$data = [
			'TerminalKey' => $input['TerminalKey'],
			'PaymentId'   => $input['PaymentId'],
		];

		$response = $this->getPayment($data, $params);

		// Prepare status identifier
		$statuses = array(
			'AUTHORIZED' => 'authorized',
			'CONFIRMED'  => 'paid',
			'REVERSED'   => 'return',
		);

		$payStatus = (!empty($response->get('Status'))) ? $response->get('Status') : 0;
		$status    = (isset($statuses[$payStatus])) ? $statuses[$payStatus] : 'fail';

		if ($status === 'paid')
		{
			// Prepare transaction identifier
			$payment_id = $response->get('PaymentId');

			// Prepare order_id
			list($order_id, $time) = explode('_', $response->get('OrderId'), 2);

			if (empty($time))
			{
				header('Content-Type: text');
				echo 'OK';
				$this->app->close(200);

				return false;
			}

			$date = $time;

			return array(
				'id'                     => $order_id,
				'sum_money'              => (float) $response->get('Amount') / 100,
				'status'                 => $status,
				'transaction_identifier' => $payment_id,
				'date_unix'              => $date,
				'prepayment'             => $input['prepayment'] ?? false,
				'used_ops_id'            => $input['used_ops_id'] ?? false,
				'identifier'             => $input['identifier'] ?? false,
				'invoice_id'             => $input['invoice_id'] ?? false,
				'hard_response'          => array(
					'contentType'       => 'text',
					'body'              => 'OK',
					'error_contentType' => 'text',
					'error_body'        => 'OK',
				)
			);
		}
		else
		{
			header('Content-Type: text');
			echo 'OK';
			$this->app->close(200);

			return false;
		}
	}

	/**
	 * Method to get payment data notification.
	 *
	 * @param   array     $data    Request data.
	 * @param   Registry  $params  Atom-S Connect component params.
	 *
	 * @return  Registry  Response data on success.
	 *
	 * @throws \Exception
	 *
	 * @since  1.0.0
	 */
	protected function getPayment($data = array(), $params = null)
	{
		$errorMsg  = null;
		$errorCode = 0;
		$request   = false;

		try
		{
			$error   = false;
			$request = $this->sendRequest('/GetState', $data, $params);
		}
		catch (\Exception $e)
		{
			$error     = true;
			$errorMsg  = $e->getMessage();
			$errorCode = $e->getCode();
		}

		// Error
		if ($error) throw new \Exception($errorMsg, $errorCode);

		return $request;
	}

	/**
	 * Method to get payment success data.
	 *
	 * @param   array     $input   The input data array.
	 * @param   Registry  $params  Atom-S Connect component params.
	 *
	 * @return  array|false  Create Atom-S payment success data on success, false on failure.
	 *
	 * @throws  \Exception
	 *
	 * @since  1.0.0
	 */
	public function onAtomsPaymentSuccess($input, $params)
	{
		// Prepare order_id
		list($order_id, $time) = explode('_', $input['OrderId'], 2);

		return array(
			'order_id' => $order_id,
			'request'  => false,
		);
	}

	/**
	 * Method to get payment error data.
	 *
	 * @param   array     $input   The input data array.
	 * @param   Registry  $params  Atom-S Connect component params.
	 *
	 * @return  array|false  Create Atom-S payment error data on success, false on failure.
	 *
	 * @throws  \Exception
	 *
	 * @since  1.0.0
	 */
	public function onAtomsPaymentError($input, $params)
	{
		list($order_id, $time) = explode('_', $input['OrderId'], 2);

		return array(
			'order_id'    => $order_id,
			'page_error'  => $input['Message'],
			'error_atoms' => ($params->get('tinkoff_error_atoms', 0)),
			'request'     => false,
		);
	}

	/**
	 * Method to send new order notification.
	 *
	 * @param   string    $method  The api method name.
	 * @param   array     $data    Request data.
	 * @param   Registry  $params  Atom-S Connect component params.
	 *
	 * @return  Registry  Response data on success.
	 *
	 * @throws \Exception
	 *
	 * @since  1.0.0
	 */
	protected function sendRequest($method, $data, $params)
	{
		if (empty($method)) throw new \Exception(Text::_('PLG_JATOMS_TINKOFF_ERROR_METHOD_NOT_FOUND'));

		if (!is_array($data)) $data = (new Registry($data))->toArray();

		$data['Token']       = $this->getToken($data, $params->get('tinkoff_api_secret'));
		$data['TerminalKey'] = $params->get('tinkoff_terminal_key');

		$url = 'https://securepay.tinkoff.ru/v2' . $method;

		$data = json_encode($data);

		$headers  = array('Content-Type' => 'application/json');
		$response = (new Http())->post($url, $data, $headers, 20);

		if ($response->code !== 200)
		{
			preg_match('#<title>(.*)</title>#', $response->body, $matches);
			$text = (!empty($matches[1])) ? $matches[1] : 'Unknown';
			throw new \Exception($text, $response->code);
		}

		$body = $response->body;

		$response = new Registry($body);

		// Debug
		LogHelper::addDebug('plg_jatoms_tinkoff', 'Send request response', 'Result', $response->toArray());

		if (!$response->get('Success') && !empty($response->get('Message')))
		{
			throw new \Exception($response->get('Message'));
		}

		return $response;
	}

	/**
	 * @param   array   $data  .
	 *
	 * @param   string  $pass  .
	 *
	 * @return false|string.
	 *
	 * @since 1.0.0
	 */
	private function getToken($data, $pass)
	{
		$data['Password'] = $pass;
		$result           = [];
		foreach ($data as $key => $item)
		{
			if (!is_object($item) && !is_array($item))
			{
				$result[$key] = $item;
			}
		}
		ksort($result);
		unset($result['Token']);
		$values = implode('', array_values($result));
		$token  = hash('sha256', $values);

		return $token;
	}
}