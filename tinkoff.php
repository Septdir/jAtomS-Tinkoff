<?php
/*
 * @package    AtomS Connect - Tinkoff
 * @version    1.0.7
 * @author     Atom-S - atom-s.com
 * @copyright  Copyright (c) 2017 - 2022 Atom-S LLC. All rights reserved.
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link       https://atom-s.com
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\Helpers\StringHelper;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;

Log::addLogger(
	['text_file' => 'plg_jatoms_payment_tinkoff.php'],
	Log::ALL,
	['plg_jatoms_payment_tinkoff']
);

class plgJAtomSTinkoff extends CMSPlugin
{
	/**
	 * Loads the application object.
	 *
	 * @var  CMSApplications
	 *
	 * @since  1.0.0
	 */
	protected $app = null;

	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var  boolean
	 *
	 * @since   1.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Method to change forms.
	 *
	 * @param   Form   $form  The form to be altered.
	 * @param   mixed  $data  The associated data for the form.
	 *
	 * @throws  Exception
	 *
	 * @since  1.0.0
	 */
	public function onjAtomSPrepareForm($form, $data)
	{
		if ($form->getName() === 'com_config.component')
		{
			Form::addFormPath(__DIR__ . '/forms');
			Form::addFieldPath(__DIR__ . '/fields');
			$form->loadFile('config');
		}
	}

	/**
	 * Method to create order.
	 *
	 * @param   string    $context  The context of the content being passed to the plugin.
	 * @param   object    $order    Order data object.
	 * @param   object    $tour     Tour data object.
	 * @param   Registry  $params   jAtomS component params.
	 * @param   array     $links    jAtomS plugin links.
	 *
	 * @throws  Exception
	 *
	 * @return  array|false  Payment redirect data on success, False on failure.
	 *
	 * @since  1.0.0
	 */
	public function onJAtomSPaymentPay($context, $order, $tour, $links, $params)
	{
		if ($context !== 'com_jatoms.connect') return false;

		$result = array(
			'pay_instant' => ($params->get('tinkoff_pay_instant', 1)),
			'link'        => false,
		);

		// Prepare links
		$OrderId = $order->order->id . '_' . Factory::getDate()->toUnix();

		// Prepare data
		$amount  = (float) $order->order->cost->value * 100;
		$name    = $this->generateProductName($order, $tour);
		$product = array(
			'Name'            => $name,
			'Price'           => $amount,
			'Quantity'        => 1.00,
			'Amount'          => $amount,
			'PaymentMethod'   => $params->get('tinkoff_kassa_method', 'full_prepayment'),
			'PaymentObject'   => $params->get('tinkoff_kassa_object', 'service'),
			'Tax'             => $params->get('tinkoff_vat'),
			'measurementUnit' => "шт",
			'ShopCode'        => $tour->id,
		);
		$receipt = array(
			'Taxation' => $params->get('tinkoff_taxation', 'osn'),
			'Payments' => array('Electronic' => $amount),
			'Items'    => array($product),
		);

		$data = array(
			'TerminalKey' => $params->get('tinkoff_terminal_key'),
			'Amount'      => $amount,
			'Description' => $name,
			'OrderId'     => $OrderId,
		);

		if (!empty($order->user))
		{
			if (!empty($order->user->email))
			{
				$data['Email']    = $order->user->email;
				$receipt['Email'] = $order->user->email;
			}

			if (!empty($order->user->phone))
			{
				$data['Phone']    = $order->user->phone;
				$receipt['Phone'] = $order->user->phone;
			}
		}
		$data['Receipt'] = $receipt;

		// Add secondary terminal
		if ($params->get('tinkoff_secondary', 0))
		{
			$tours = ArrayHelper::toInteger($params->get('tinkoff_secondary_tours', array()));
			if (in_array((int) $tour->id, $tours)) $data['terminal'] = 'secondary';
		}

		if ($params->get('tinkoff_tertiary', 0))
		{
			$tours = ArrayHelper::toInteger($params->get('tinkoff_tertiary_tours', array()));
			if (in_array((int) $tour->id, $tours)) $data['terminal'] = 'tertiary';
		}

		// Debug
		if ($this->isDebug())
		{
			$this->log('Register order data', $data);
		}

		$jsonData          = new stdClass();
		$jsonData->Receipt = $receipt;
		$data['jsonData']  = (new Registry($jsonData))->toString('json', array('bitmask' => JSON_UNESCAPED_UNICODE));

		//Create transaction request
		$request = $this->sendRequest('/Init', $data, $params);

		$result['link'] = $request->get('PaymentURL');

		return $result;
	}

	/**
	 * Method to get payment confirm data.
	 *
	 * @param   string    $context  The context of the content being passed to the plugin.
	 * @param   array     $input    The input data array.
	 * @param   Registry  $params   jAtomS component params.
	 *
	 * @throws  Exception
	 *
	 * @return  array|false  Create Atom-S payment confirm data on success, false on failure.
	 *
	 * @since  1.0.0
	 */
	public function onJAtomSPaymentConfirm($context, $input, $params)
	{
		if ($context !== 'com_jatoms.connect') return false;

		// Debug
		if ($this->isDebug())
		{
			$this->log('Confirm input data', $input);
		}

		if ($input['Status'] !== 'CONFIRMED')
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

		$status = (isset($statuses[$payStatus])) ? $statuses[$payStatus] : 'fail';

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
	 * @param   Registry  $params  jAtomS component params.
	 *
	 * @throws Exception
	 *
	 * @return  Registry  Response data on success.
	 *
	 * @since  1.0.0
	 */
	protected function getPayment($data = array(), $params = null)
	{
		$errorMsg  = null;
		$errorCode = 0;
		$request   = false;

		// Primary
		try
		{
			$error   = false;
			$request = $this->sendRequest('/GetState', $data, $params);
		}
		catch (Exception $e)
		{
			$error     = true;
			$errorMsg  = $e->getMessage();
			$errorCode = $e->getCode();
		}

		// Secondary
		if ($error && $params->get('tinkoff_secondary', 0))
		{
			try
			{
				$error            = false;
				$data['terminal'] = 'secondary';
				$request          = $this->sendRequest('/GetState', $data, $params);
			}
			catch (Exception $e)
			{
				$error     = true;
				$errorMsg  = $e->getMessage();
				$errorCode = $e->getCode();
			}
		}

		// Tertiary
		if ($error && $params->get('tinkoff_tertiary', 0))
		{
			try
			{
				$error            = false;
				$data['terminal'] = 'tertiary';
				$request          = $this->sendRequest('/GetState', $data, $params);
			}
			catch (Exception $e)
			{
				$error     = true;
				$errorMsg  = $e->getMessage();
				$errorCode = $e->getCode();
			}
		}

		// Error
		if ($error) throw new Exception($errorMsg, $errorCode);

		return $request;
	}

	/**
	 * Method to get payment success data.
	 *
	 * @param   string    $context  The context of the content being passed to the plugin.
	 * @param   array     $input    The input data array.
	 * @param   Registry  $params   jAtomS component params.
	 *
	 * @throws  Exception
	 *
	 * @return  array|false  Create Atom-S payment success data on success, false on failure.
	 *
	 * @since  1.0.0
	 */
	public function onJAtomSPaymentSuccess($context, $input, $params)
	{
		if ($context !== 'com_jatoms.connect') return false;

		// Prepare order_id
		list($order_id, $time) = explode('_', $input['OrderId'], 2);

		return array(
			'order_id'      => $order_id,
			'success_atoms' => ($params->get('tinkoff_success_atoms', 0)),
			'request'       => false,
		);
	}

	/**
	 * Method to get payment error data.
	 *
	 * @param   string    $context  The context of the content being passed to the plugin.
	 * @param   array     $input    The input data array.
	 * @param   Registry  $params   jAtomS component params.
	 *
	 * @throws  Exception
	 *
	 * @return  array|false  Create Atom-S payment error data on success, false on failure.
	 *
	 * @since  1.0.0
	 */
	public function onJAtomSPaymentError($context, $input, $params)
	{
		if ($context !== 'com_jatoms.connect') return false;

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
	 * @param   Registry  $params  jAtomS component params.
	 *
	 * @throws Exception
	 *
	 * @return  Registry  Response data on success.
	 *
	 * @since  1.0.0
	 */
	protected function sendRequest($method, $data, $params)
	{
		if (empty($method)) throw new Exception(Text::_('PLG_JATOMS_TINKOFF_ERROR_METHOD_NOT_FOUND'));

		if (!is_array($data)) $data = (new Registry($data))->toArray();
		$data['Token'] = $this->getToken($data, $params->get('tinkoff_api_secret'));

		$selector = 'tinkoff';

		if (!empty($data['terminal']))
		{
			if ($data['terminal'] === 'secondary') $selector = 'tinkoff_secondary';
			elseif ($data['terminal'] === 'tertiary') $selector = 'tinkoff_tertiary';
			unset($data['terminal']);

			$data['TerminalKey'] = $params->get($selector . '_terminal_key');
			$data['Token']       = $this->getToken($data, $params->get($selector . '_api_secret'));
		}

		$url = 'https://securepay.tinkoff.ru/v2' . $method;

		$data = json_encode($data);

		$headers  = array('Content-Type' => 'application/json');
		$response = (new Http())->post($url, $data, $headers, 20);

		if ($response->code !== 200)
		{
			preg_match('#<title>(.*)</title>#', $response->body, $matches);
			$text = (!empty($matches[1])) ? $matches[1] : 'Unknown';
			throw new Exception($text, $response->code);
		}

		$body = $response->body;

		$response = new Registry($body);

		// Debug
		if ($this->isDebug())
		{
			$this->log('Send request response', $response->toArray());
		}

		if (!$response->get('Success') && !empty($response->get('Message')))
		{
			throw new Exception($response->get('Message'));
		}

		return $response;
	}

	/**
	 * Method to generate product label.
	 *
	 * @param   object  $order  Order data object.
	 * @param   object  $tour   Tour data object.
	 *
	 * @return string Generated product label.
	 *
	 * @since  1.0.2
	 */
	protected function generateProductName($order, $tour)
	{
		$duration = false;
		if (!empty($tour->duration->get('min')) && !empty($tour->duration->get('max')))
		{
			if ($tour->duration->get('type') === 'multi-day')
			{
				$duration = Text::sprintf('COM_JATOMS_DURATION_N_DAYS_MIN_MAX',
					$tour->duration->get('min'), $tour->duration->get('max'));
			}
			elseif ($tour->duration->get('type') === 'one-day')
			{
				$duration = Text::sprintf('COM_JATOMS_DURATION_N_HOURS_MIN_MAX',
					$tour->duration->get('min'), $tour->duration->get('max'));
			}
		}
		elseif ($tour->duration->get('type') === 'multi-day' && !empty($tour->duration->get('days')))
		{
			$duration = Text::plural('COM_JATOMS_DURATION_N_DAYS', $tour->duration->get('days'));
		}
		elseif ($tour->duration->get('type') === 'one-day' && !empty($tour->duration->get('hours')))
		{
			$duration = Text::plural('COM_JATOMS_DURATION_N_HOURS', $tour->duration->get('hours'));
			if (!empty($tour->duration->get('minutes')))
			{
				$duration .= ' ' . Text::plural('COM_JATOMS_DURATION_N_MINUTES',
						$tour->duration->get('minutes'));
			}
		}

		// Truncate tour name (Tinkoff limit for param - 128 symbols)
		$tourName = StringHelper::truncate($tour->name, 80);
		$tourID   = 'ID ' . $tour->id;

		return implode(', ', array($tourID, $tourName, $duration,
			Factory::getDate($order->order->start_date)->format(Text::_('COM_JATOMS_DATE_STANDARD')),
			Text::sprintf('COM_JATOMS_ORDER_NUMBER', $order->order->id),
		));
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

		return hash('sha256', $values);
	}

	/**
	 * Gets the debug param.
	 *
	 * @return  bool  True if debug on, false otherwise.
	 *
	 * @since   1.0.3
	 */
	private function isDebug(): bool
	{
		if (!isset($this->debug))
		{
			$this->debug = ComponentHelper::getParams('com_jatoms')->get('tinkoff_debug', 0);
		}

		return (bool) $this->debug;
	}

	/**
	 * Add log message
	 *
	 * @param   string        $name             Name of the log message
	 * @param   string|array  $message          Log message
	 * @param   bool          $needHideMessage  Flag to hide credentials in message string
	 * @param   string        $type             Type of log
	 *
	 * @return  bool
	 *
	 * @since   1.0.3
	 */
	public function log($name, $message, $needHideMessage = false, $type = JLog::DEBUG): bool
	{
		$logCategory = 'plg_jatoms_payment_tinkoff';

		if (empty($message))
		{
			return true;
		}

		if (is_array($message) || is_object($message))
		{
			$message = print_r($message, true);
		}
		else
		{
			if ($needHideMessage)
			{
				$message = substr($message, 0, 5) . str_repeat('*', 3);
			}
		}

		// Add message to log
		Log::add($name . ': ' . $message, $type, $logCategory);

		return true;
	}
}