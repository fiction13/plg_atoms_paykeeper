<?php
/*
 * @package    jAtomS - PayKeeper Plugin
 * @version    __DEPLOY_VERSION__
 * @author     Atom-S - atom-s.com
 * @copyright  Copyright (c) 2017 - 2022 Atom-S LLC. All rights reserved.
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link       https://atom-s.com
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\Log\Log;

Log::addLogger(
	['text_file' => 'plg_jatoms_payment_paykeeper.php'],
	Log::ALL,
	['plg_jatoms_payment_paykeeper']
);

class plgJAtomSPayKeeper extends CMSPlugin
{
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
	 * @return  array|false  Payment redirect data on success, False on failure.
	 *
	 * @throws  Exception
	 *
	 * @since  1.0.0
	 */
	public function onJAtomSPaymentPay($context, $order, $tour, $links, $params)
	{
		if ($context !== 'com_jatoms.connect') return false;

		$result = array(
			'pay_instant' => true,
			'link'        => ''
		);

		$terminal = 1;

		// Add secondary terminal
		if ($params->get('paykeeper_secondary', 0))
		{
			$tours = ArrayHelper::toInteger($params->get('paykeeper_secondary_tours', array()));

			if (in_array((int) $tour->id, $tours))
			{
				$data['terminal'] = 'secondary';
				$terminal = 2;
			}
		}

		// Add tertiary terminal
		if ($params->get('paykeeper_tertiary', 0))
		{
			$tours = ArrayHelper::toInteger($params->get('paykeeper_tertiary_tours', array()));
			if (in_array((int) $tour->id, $tours))
			{
				$data['terminal'] = 'tertiary';
				$terminal = 3;
			}
		}

		// Prepare links
		$orderId = $order->order->id . '_' . Factory::getDate()->toUnix() . '_' . $terminal;

		// Product
		$product = [
			'name'         => $this->generateProductLabel($order, $tour),
			'price'        => (float) $order->order->cost->value,
			'quantity'     => 1,
			'sum'          => (float) $order->order->cost->value,
			'payment_type' => $params->get('paykeeper_kassa_method', 'prepay'),
			'tax'          => $params->get('paykeeper_vat', 'none'),
			'item_type'    => $params->get('paykeeper_kassa_object', 'service'),
		];

		// Cart
		$cart = array($product);

		// All data
		$data = [
			'cart'         => (new Registry($cart))->toString('json', array('bitmask' => JSON_UNESCAPED_UNICODE)),
			'pay_amount'   => (float) $order->order->cost->value,
			'service_name' => $this->generateProductLabel($order, $tour),
			'orderid'      => $orderId
		];

		// User data
		if (!empty($order->user))
		{
			if (!empty($order->user->email))
			{
				$data['client_email'] = $order->user->email;
			}

			if (!empty($order->user->phone))
			{
				$data['client_phone'] = $order->user->phone;
			}
		}

		// Debug
        if ($this->isDebug())
		{
			$this->log('On pay data', $data);
		}

		// Create transaction request
		$request        = $this->sendRequest('change/invoice/preview/', $data, $params);
		$result['link'] = $request->get('invoice_url');

		// Set orderId to cookie
		Factory::getApplication()->input->cookie->set('atoms_paykeeper_order_id', $orderId);

		return $result;
	}

	/**
	 * Method to get payment confirm data.
	 *
	 * @param   string    $context  The context of the content being passed to the plugin.
	 * @param   array     $input    The input data array.
	 * @param   Registry  $params   jAtomS component params.
	 *
	 * @return  array|false  Create Atom-S payment confirm data on success, false on failure.
	 *
	 * @throws  Exception
	 *
	 * @since  1.0.0
	 */
	public function onJAtomSPaymentConfirm($context, $input, $params)
	{
		if ($context !== 'com_jatoms.connect') return false;

		// Debug
        if ($this->isDebug())
		{
			$this->log('Callback input data', $input);
		}

		$request       = $this->getPayment(array('transactionId' => $input['id']), $params);
		$invoiceStatus = $request->get('status');

		// Prepare status identifier
		$statuses = array(
			'obtained' => 'paid',
			'stuck'    => 'paid',
			'success'  => 'paid',
			'canceled' => 'fail',
			'failed'   => 'fail',
			'refunded' => 'return'
		);

		$status = (isset($statuses[$invoiceStatus])) ? $statuses[$invoiceStatus] : 'fail';

		// Prepare transaction identifier
		$transaction_identifier = $input['id'];

		// Prepare order_id
		list($order_id, $time, $terminal) = explode('_', $request->get('orderid'), 3);

		// Secret key
		if ((int) $terminal === 1)
		{
			$secretKey = $params->get('paykeeper_secret_key', 0);
		}
		elseif ((int) $terminal === 2)
		{
			$secretKey = $params->get('paykeeper_secondary_secret_key', 0);
		}
		else
		{
			$secretKey = $params->get('paykeeper_tertiary_secret_key', 0);
		}

		// Close if no time or not paid
		if (empty($time) || $status !== 'paid')
		{
			header('Content-Type: text');
			echo 'OK ' . md5($transaction_identifier . $secretKey);
			Factory::getApplication()->close(200);

			return false;
		}

		// Prepare date
		$dateSuccess = $request->get('success_datetime') ?? $request->get('obtain_datetime');
		$date        = ($status === 'paid') ? Factory::getDate($dateSuccess)->toUnix() : $time;
		$current     = $time;

		if ($date > $current) $date = $current;

		return array(
			'id'                     => $order_id,
			'sum_money'              => (float) $request->get('pay_amount'),
			'status'                 => $status,
			'transaction_identifier' => $transaction_identifier,
			'date_unix'              => $date,
			'hard_response'          => array(
				'contentType'       => 'text',
				'body'              => 'OK ' . md5($transaction_identifier . $secretKey),
				'error_contentType' => 'text',
				'error_body'        => 'OK ' . md5($transaction_identifier . $secretKey),
			)
		);
	}

	/**
	 * Method to get payment data notification.
	 *
	 * @param   array     $data    Request data.
	 * @param   Registry  $params  jAtomS component params.
	 *
	 * @return  Registry  Response data on success.
	 *
	 * @throws Exception
	 *
	 * @since  1.0.0
	 */
	protected function getPayment($data = array(), $params = null)
	{
		$errorMsg  = null;
		$errorCode = 0;
		$request   = false;
		$method    = 'info/payments/byid/?id=' . $data['transactionId'];

		// Primary
		try
		{
			$error   = false;
			$request = $this->getRequest($data, $method, $params, 'id');
		}
		catch (Exception $e)
		{
			$error     = true;
			$errorMsg  = $e->getMessage();
			$errorCode = $e->getCode();
		}

		// Secondary
		if ($error && $params->get('paykeeper_secondary', 0))
		{
			try
			{
				$error            = false;
				$data['terminal'] = 'secondary';
				$request          = $this->getRequest($data, $method, $params, 'id');
			}
			catch (Exception $e)
			{
				$error     = true;
				$errorMsg  = $e->getMessage();
				$errorCode = $e->getCode();
			}
		}

		// Tertiary
		if ($error && $params->get('paykeeper_tertiary', 0))
		{
			try
			{
				$error            = false;
				$data['terminal'] = 'tertiary';
				$request          = $this->getRequest($data, $method, $params, 'id');
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

		// Debug
        if ($this->isDebug())
		{
			$this->log('Get payment request', $request);
		}

		return $request;
	}

	/**
	 * Method to get payment success data.
	 *
	 * @param   string    $context  The context of the content being passed to the plugin.
	 * @param   array     $input    The input data array.
	 * @param   Registry  $params   jAtomS component params.
	 *
	 * @return  array|false  Create Atom-S payment success data on success, false on failure.
	 *
	 * @throws  Exception
	 *
	 * @since  1.0.0
	 */
	public function onJAtomSPaymentSuccess($context, $input, $params)
	{
		if ($context !== 'com_jatoms.connect') return false;

		// Debug
        if ($this->isDebug())
		{
			$this->log('Success input data', $input);
		}

		// Prepare order_id
		list($order_id, $time) = explode('_', Factory::getApplication()->input->cookie->get('atoms_paykeeper_order_id'), 2);

		// Clear orderId cookie
		Factory::getApplication()->input->cookie->set('atoms_paykeeper_order_id', '');

		return array(
			'order_id'      => $order_id,
			'success_atoms' => ($params->get('paykeeper_success_atoms', 0)),
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
	 * @return  array|false  Create Atom-S payment error data on success, false on failure.
	 *
	 * @throws  Exception
	 *
	 * @since  1.0.0
	 */
	public function onJAtomSPaymentError($context, $input, $params)
	{
		if ($context !== 'com_jatoms.connect') return false;

		// Debug
        if ($this->isDebug())
		{
			$this->log('Error input data', $input);
		}

		// Prepare order_id
		list($order_id, $time) = explode('_', Factory::getApplication()->input->cookie->get('atoms_paykeeper_order_id'), 2);

		// Clear orderId cookie
		Factory::getApplication()->input->cookie->set('atoms_paykeeper_order_id', '');

		return array(
			'order_id'    => $order_id,
			'page_error'  => Text::_('PLG_JATOMS_PAYKEEPER_ERROR_ORDER_PAGE'),
			'error_atoms' => ($params->get('paykeeper_error_atoms', 0)),
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
	 * @return  Registry  Response data on success.
	 *
	 * @throws Exception
	 *
	 * @since  1.0.0
	 */
	protected function sendRequest($method, $data, $params)
	{
		if (empty($method)) throw new Exception(Text::_('PLG_JATOMS_PAYKEEPER_ERROR_METHOD_NOT_FOUND'));

		if (!is_array($data)) $data = (new Registry($data))->toArray();

		// Get token
		$token         = $this->getRequest($data, 'info/settings/token', $params, 'token');
		$data['token'] = $token->get('token');

		// Terminal
		$selector = 'paykeeper';
		if (isset($data['terminal']) && !empty($data['terminal']))
		{
			if ($data['terminal'] === 'secondary') $selector = 'paykeeper_secondary';
			elseif ($data['terminal'] === 'tertiary') $selector = 'paykeeper_tertiary';
			unset($data['terminal']);
		}

		$headers = array('Content-Type' => 'application/x-www-form-urlencoded');

		$option = new Registry();
		$option->set('userauth', $params->get($selector . '_user'));
		$option->set('passwordauth', $params->get($selector . '_password'));

		// Get payment url
		$url      = rtrim($params->get('paykeeper_server'), '/') . '/' . $method;
		$response = (new Http($option))->post($url, $data, $headers, 20);
		$body     = $response->body;

		// Debug
        if ($this->isDebug())
		{
			$this->log('Send request data', $response);
		}

		if ($response->code !== 200)
		{
			preg_match('#<title>(.*)</title>#', $body, $matches);
			$text = (!empty($matches[1])) ? $matches[1] : 'Unknown';
			throw new Exception($text, $response->code);
		}

		$response = new Registry($body);

		if (!$response->get('invoice_id'))
		{
			throw new Exception($response->get(Text::_('PLG_JATOMS_PAYKEEPER_ERROR_INVOICE_NOT_FOUND')));
		}

		return $response;
	}

	/**
	 * Method to send new order notification.
	 *
	 * @param   string    $method  The api method name.
	 * @param   array     $data    Request data.
	 * @param   Registry  $params  jAtomS component params.
	 *
	 * @return  Registry  Response data on success.
	 *
	 * @throws Exception
	 *
	 * @since  1.0.0
	 */
	protected function getRequest($data, $method, $params, $checkValue)
	{
		if (empty($method)) throw new Exception(Text::_('PLG_JATOMS_PAYKEEPER_ERROR_METHOD_NOT_FOUND'));

		if (!is_array($data)) $data = (new Registry($data))->toArray();

		$selector = 'paykeeper';
		if (!empty($data['terminal']))
		{
			if ($data['terminal'] === 'secondary') $selector = 'paykeeper_secondary';
			elseif ($data['terminal'] === 'tertiary') $selector = 'paykeeper_tertiary';
			unset($data['terminal']);
		}

		$headers = array('Content-Type' => 'application/x-www-form-urlencoded');

		$option = new Registry();
		$option->set('userauth', $params->get($selector . '_user'));
		$option->set('passwordauth', $params->get($selector . '_password'));

		// Get token
		$url      = rtrim($params->get('paykeeper_server'), '/') . '/' . $method;
		$response = (new Http($option))->get($url, $headers, 20);
		$body     = $response->body;

		// Debug
        if ($this->isDebug())
		{
			$this->log('Get request response', $response);
		}

		if ($response->code !== 200)
		{
			preg_match('#<title>(.*)</title>#', $body, $matches);
			$text = (!empty($matches[1])) ? $matches[1] : 'Unknown';
			throw new Exception($text, $response->code);
		}

		// For payment
		if ($checkValue === 'id')
		{
			$response = json_decode($body, true);
			$response = new Registry($response[0]);
		} else {
			$response = new Registry($body);
		}

		if (!$response->get($checkValue))
		{
			throw new Exception($response->get(Text::_('PLG_JATOMS_PAYKEEPER_ERROR_' . strtoupper($checkValue) . '_NOT_FOUND')));
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
	 * @since  1.0.0
	 */
	protected function generateProductLabel($order, $tour)
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

		return implode(', ', array($tour->name, $duration,
			Factory::getDate($order->order->start_date)->format(Text::_('COM_JATOMS_DATE_STANDARD')),
			Text::sprintf('COM_JATOMS_ORDER_NUMBER', $order->order->id),
		));
	}

	/**
	 * Gets the debug param.
     *
	 * @return  bool  True if debug on, false otherwise.
	 *
	 * @since   1.0.0
	 */
	private function isDebug():bool
	{
		if (!isset($this->debug))
		{
			$this->debug = $this->params->get('debug', 0);
		}

		return (bool) $this->debug;
	}

    /**
     * Add log message
     *
     * @param   string          $name               Name of the log message
     * @param   string|array    $message            Log message
     * @param   bool            $needHideMessage    Flag to hide credentials in message string
     * @param   string          $type               Type of log
     *
     * @return  bool
     */
    public function log($name, $message, $needHideMessage = false, $type = JLog::DEBUG):bool
    {
        $logCategory = 'plg_jatoms_payment_paykeeper';

        if (empty($message)) {
            return true;
        }

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        } else {
            if ($needHideMessage) {
                $message = substr($message, 0, 5) . str_repeat('*', 3);
            }
        }

        // Add message to log
        Log::add($name.': '.$message, $type, $logCategory);

        return true;
    }
}