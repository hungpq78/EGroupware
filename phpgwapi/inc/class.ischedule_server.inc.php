<?php
/**
 * EGroupware: iSchedule server
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package api
 * @subpackage groupdav
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @copyright (c) 2012 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

/**
 * iSchedule server: serverside of iSchedule
 *
 * @link https://tools.ietf.org/html/draft-desruisseaux-ischedule-01 iSchedule draft from 2010
 */
class ischedule_server
{
	/**
	 * iSchedule xml namespace
	 */
	const ISCHEDULE = 'urn:ietf:params:xml:ns:ischedule';

	/**
	 * Own iSchedule version
	 */
	const VERSION = '1.0';

	/**
	 * Required headers in DKIM signature (DKIM-Signature is always a required header!)
	 */
	const REQUIRED_DKIM_HEADERS = 'Content-Type:Host:Originator:Recipient';

	/**
	 * Serve an iSchedule request
	 */
	public function ServeRequest()
	{
		// install our own exception handler sending exceptions as http status
		set_exception_handler(array(__CLASS__, 'exception_handler'));

		switch($_SERVER['REQUEST_METHOD'])
		{
			case 'GET':
				$this->get();
				break;

			case 'POST':
				$this->post();
				break;

			default:
				error_log(__METHOD__."() invalid iSchedule request using {$_SERVER['REQUEST_METHOD']}!");
				header("HTTP/1.1 400 Bad Request");
		}
	}

	static $supported_components = array('VEVENT', 'VFREEBUSY', 'VTODO');
	/**
	 * Requiremnt for originator to match depending on method
	 *
	 * @var array method => array('ORGANIZER','ATTENDEE')
	 * @link https://tools.ietf.org/html/draft-desruisseaux-ischedule-01#section-6.1
	 */
	static $supported_method2origin_requirement = array(
		//'PUBLISH' => null,	// no requirement
		'REQUEST' => array('ORGANIZER', 'ATTENDEE'),
		'REPLY'   => array('ATTENDEE'),
		'ADD'     => array('ORGANIZER'),
		'CANCEL'  => array('ORGANIZER'),
		//'REFRESH' => null,
		//'COUNTER' => array('ATTENDEE'),
		//'DECLINECOUNTER' => array('ORGANIZER'),
	);

	/**
	 * Serve an iSchedule POST request
	 */
	protected function post()
	{
		// get and verify required headers
		$headers = array();
		foreach($_SERVER as $name => $value)
		{
			$name = strtolower(str_replace('_', '-', $name));
			list($first, $rest) = explode('-', $name, 2);
			switch($first)
			{
				case 'content':
					$headers[$name] = $value;
					break;
				case 'http':
					$headers[$rest] = $value;
					break;
			}
		}
		if (($missing = array_diff(explode(':', strtolower(self::REQUIRED_DKIM_HEADERS.':DKIM-Signature')), array_keys($headers))))
		{
error_log(array2string(array_keys($headers)));
			throw new Exception ('Bad Request: missing required headers: '.implode(', ', $missing), 400);
		}

		// get raw request body
		$ical = file_get_contents('php://input');

		// validate dkim signature
		if (!self::dkim_validate($headers, $ical, $error))
		{
			throw new Exception('Bad Request: DKIM signature invalid: '.$error, 400);
		}
		// check if recipient is a user
		// ToDo: multiple recipients
		if (!($account_id = $GLOBALS['egw']->accounts->name2id($headers['recipient'], 'account_email')))
		{
			throw new Exception('Bad Request: unknown recipient', 400);
		}
		// create enviroment for recipient user, as we act on his behalf
		$GLOBALS['egw']->session->account_id = $account_id;
		$GLOBALS['egw']->session->account_lid = $GLOBALS['egw']->accounts->id2name($account_id);
		//$GLOBALS['egw']->session->account_domain = $domain;
		$GLOBALS['egw_info']['user']  = $GLOBALS['egw']->session->read_repositories();
		translation::init();

		// check originator is allowed to iSchedule with recipient
		// ToDo: preference for user/admin to specify with whom to iSchedule: $allowed_origins
		$allowed_origins = preg_split('/, ?/', $GLOBALS['egw_info']['user']['groupdav']['ischedule_allowed_origins']);
		/* disabled 'til UI is ready to specifiy
		list(,$originator_domain) = explode('@', $headers['Originator']);
		if (!in_array($headers['Originator'], $allowed_orgins) && !in_array($originator_domain, $allowed_origins))
		{
			throw new Exception('Forbidden', 403);
		}*/

		// check method and component of Content-Type are valid
		if (!preg_match('/component=([^;]+)/i', $headers['content-type'], $matches) ||
			(!in_array($component=strtoupper($matches[1]), self::$supported_components)))
		{
			throw new Exception ('Bad Request: missing or unsupported component in Content-Type header', 400);
		}
		if (!preg_match('/method=([^;]+)/i', $headers['content-type'], $matches) ||
			(!isset(self::$supported_method2origin_requirement[$method=strtoupper($matches[1])])) ||
			$component == 'VFREEBUSY' && $method != 'REQUEST')
		{
			throw new Exception ('Bad Request: missing or unsupported method in Content-Type header', 400);
		}
		// parse iCal
		// code copied from calendar_groupdav::outbox_freebusy_request for now
		include_once EGW_SERVER_ROOT.'/phpgwapi/inc/horde/lib/core.php';
		$vcal = new Horde_iCalendar();
		if (!$vcal->parsevCalendar($ical, 'VCALENDAR', 'utf-8'))
		{
			throw new Exception('Bad Request: Failed parsing iCal', 400);
		}
		$version = $vcal->getAttribute('VERSION');
		$handler = new calendar_ical();
		$handler->setSupportedFields('GroupDAV',$this->agent);
		$handler->calendarOwner = $handler->user = 0;	// to NOT default owner/organizer to something
		if (!($vcal_comp = $vcal->getComponent(0)) ||
			!($event = $handler->vevent2egw($vcal_comp, $version, $handler->supportedFields,
				$principalURL='', $check_component='Horde_iCalendar_'.strtolower($component))))
		{
			throw new Exception('Bad Request: Failed converting iCal', 400);
		}

		// validate originator matches organizer or attendee
		$originator_requirement = self::$supported_method2origin_requirement[$method];
		if (isset($originator_requirement))
		{
			$matches = false;
			foreach($originator_requirement as $requirement)
			{
				if ($requirement == 'ORGANIZER' &&
					($event['organizer'] == $headers['originator'] || strpos($event['organizer'], '<'.$headers['originator'].'>') !== false) ||
					$requirement == 'ATTENDEE' &&
					(in_array('e'.$headers['originator'], $event['participants']) ||
					// ToDO: Participant could have CN, need to check that too
					 $originator_account_id = $GLOBALS['egw']->accounts->name2id($headers['originator'], 'account_email') &&
					 	in_array($originator_account_id, $event['participants'])))
			 	{
			 		$matches = true;
			 		break;	// no need to try further as we OR
			 	}
			}
			if (!$matches)
			{
				throw new Exception('Bad Request: originator invalid for given '.$component.'!', 400);
			}
		}

		$xml = new XMLWriter;
		$xml->openMemory();
		$xml->setIndent(true);
		$xml->startDocument('1.0', 'UTF-8');
		$xml->startElementNs(null, 'schedule-response', self::ISCHEDULE);	// null = no prefix

		switch($component)
		{
			case 'VFREEBUSY':
				$this->vfreebusy($event, $handler, $vcal_comp, $xml);
				break;

			case 'VEVENT':
				$this->vevent($event, $handler, $component, $xml);
				break;

			default:
				throw new exception('Not yet implemented!');
		}

		$xml->endElement();	// schedule-response
		$xml->endDocument();

		header('Content-type: text/xml; charset=UTF-8');
		header('iSchedule-Version: '.self::VERSION);

		echo $xml->outputMemory();
	}

	/**
	 * Handle VEVENT component
	 *
	 * @param array $event
	 * @param calendar_ical $handler
	 * @param string $ical
	 * @param XMLWriter $xml
	 */
	function vevent(array $event, calendar_ical $handler, Horde_iCalendar_vevent $component, XMLWriter $xml)
	{
		$organizer = $component->getAttribute('ORGANIZER');
		$attendees = (array)$component->getAttribute('ATTENDEE');

		$handler->importVCal($vCalendar, $eventId,
			self::etag2value($this->http_if_match), false, 0, $this->groupdav->current_user_principal, $user, $charset, $id);

		foreach($event['participants'] as $uid => $status)
		{
			$xml->startElement('response');

			$xml->writeElement('recipient', $attendee=array_shift($attendees));	// iSchedule has not DAV:href!

			if (is_numeric($uid))
			{
				$xml->writeElement('request-status', '2.0;Success');
				$xml->writeElement('responsedescription', 'Delivered to recipient');
			}
			else
			{
				$xml->writeElement('request-status', '3.7;Invalid Calendar User');
				$xml->writeElement('responsedescription', 'Recipient not a local user');
			}
			$xml->endElement();	// response
		}
	}

	/**
	 * Handle VFREEBUSY component
	 *
	 * @param array $event
	 * @param calendar_ical $handler
	 * @param Horde_iCalendar_vfreebusy $component
	 * @param XMLWriter $xml
	 */
	function vfreebusy(array $event, calendar_ical $handler, Horde_iCalendar_vfreebusy $component, XMLWriter $xml)
	{
		$organizer = $component->getAttribute('ORGANIZER');
		$attendees = (array)$component->getAttribute('ATTENDEE');

		foreach($event['participants'] as $uid => $status)
		{
			$xml->startElement('response');

			$xml->writeElement('recipient', $attendee=array_shift($attendees));	// iSchedule has not DAV:href!

			if (is_numeric($uid))
			{
				$xml->writeElement('request-status', '2.0;Success');
				$xml->writeElement('calendar-data',
					$handler->freebusy($uid, $event['end'], true, 'utf-8', $event['start'], 'REPLY', array(
						'UID' => $event['uid'],
						'ORGANIZER' => $organizer,
						'ATTENDEE' => $attendee,
					)));
			}
			else
			{
				$xml->writeElement('request-status', '3.7;Invalid Calendar User');
			}
			$xml->endElement();	// response
		}
	}

	/**
	 * Validate DKIM signature
	 *
	 * @param array $headers header-name in lowercase(!) as key
	 * @param string $body
	 * @param string &$error=null error if false returned
	 * @return boolean true if signature could be validated, false otherwise
	 * @todo other dkim q= methods: http/well-known bzw private-exchange
	 */
	public static function dkim_validate(array $headers, $body, &$error=null, $required_headers=self::REQUIRED_DKIM_HEADERS)
	{
		// parse dkim siginature
		if (!isset($headers['dkim-signature']) ||
			!preg_match_all('/[\t\s]*([a-z]+)=([^;]+);?/i', $headers['dkim-signature'], $matches))
		{
			$error = "Can't parse DKIM signature";
			return false;
		}
		$dkim = array_combine($matches[1], $matches[2]);

		if (($missing=array_diff(explode(':', strtolower($required_headers)), explode(':', strtolower($dkim['h'])))))
		{
			$error = "Missing required headers: ".implode(', ', $missing);
			return false;
		}

		// create headers array
		$dkim_headers = array();
		foreach(explode(':', strtolower($dkim['h'])) as $header)
		{
			$dkim_headers[] = $header.': '.$headers[$header];
		}
		list($dkim_unsigned) = explode('b='.$dkim['b'], 'DKIM-Signature: '.$headers['dkim-signature']);
		$dkim_unsigned .= 'b=';

		list($header_canon, $body_canon) = explode('/', $dkim['c']);
		require_once EGW_API_INC.'/php-mail-domain-signer/lib/class.mailDomainSigner.php';

		// Canonicalization for Body
		switch($body_canon)
		{
			case 'relaxed':
				$_b = mailDomainSigner::bodyRelaxCanon($body);
				break;

			case 'simple':
				$_b = mailDomainSigner::bodySimpleCanon($body);
				break;

			default:
				$error = "Unknown body canonicalization '$body_canon'";
				return false;
		}

		// Hash of the canonicalized body [tag:bh]
		list(,$hash_algo) = explode('/', $dkim['a']);
		$_bh= base64_encode(hash($hash_algo, $_b, true));

		// check body hash
		if ($_bh != $dkim['bh'])
		{
			$error = 'Body hash does NOT verify';
			error_log(__METHOD__."() body-hash='$_bh' != '$dkim[bh]'=dkim-bh $error");
			return false;
		}

		// Canonicalization Header Data
		switch($header_canon)
		{
			case 'relaxed':
				$_unsigned  = mailDomainSigner::headRelaxCanon(implode("\r\n", $dkim_headers). "\r\n".$dkim_unsigned);
				break;

			case 'simple':
				$_unsigned  = mailDomainSigner::headSimpleCanon(implode("\r\n", $dkim_headers). "\r\n".$dkim_unsigned);
				break;

			default:
				$error = "Unknown header canonicalization '$header_canon'";
				return false;
		}
error_log(__METHOD__."() unsigned='$_unsigned'");

		// fetch public key q=dns/txt method
		// ToDo other $dkim[q] methods: http/well-known bzw private-exchange
		if (!($dns = self::fetch_dns($dkim['d'], $dkim['s'])))
		{
			$error = "No public key for d='$dkim[d]' and s='$dkim[s]'";
			return false;
		}
		$public_key = "-----BEGIN PUBLIC KEY-----\n".chunk_split($dns['p'], 64, "\n")."-----END PUBLIC KEY-----\n";

		$ok = openssl_verify($_unsigned, base64_decode($dkim['b']), $public_key);

		switch($ok)
		{
			case -1:
				$error = 'Error while verifying DKIM';
				return false;

			case 0:
				$error = 'DKIM signature does NOT verify';
				error_log(__METHOD__."() unsigned='$_unsigned' $error");
				return false;
		}

		return true;
	}

	/**
	 * Fetch dns record and return parsed array
	 *
	 * @param string $domain
	 * @param string $selector
	 * @return array with values for keys parsed from eg. "v=DKIM1\;k=rsa\;h=sha1\;s=calendar\;t=s\;p=..."
	 */
	public static function fetch_dns($domain, $selector='calendar')
	{
		if (!($records = dns_get_record($host=$selector.'._domainkey.'.$domain, DNS_TXT))) return false;

		if (!isset($records[0]['text']) &&
			!preg_match_all('/[\t\s]*([a-z]+)=([^;]+);?/i', $records[0]['txt'], $matches))
		{
			return false;
		}
		return array_combine($matches[1], $matches[2]);
	}

	/**
	 * Serve an iSchedule GET request, currently only action=capabilities
	 *
	 * GET /.well-known/ischedule?action=capabilities HTTP/1.1
	 * Host: cal.example.com
	 *
	 * HTTP/1.1 200 OK
	 * Date: Mon, 15 Dec 2008 09:32:12 GMT
	 * Content-Type: application/xml; charset=utf-8
	 * Content-Length: xxxx
	 * iSchedule-Version: 1.0
	 * ETag: "afasdf-132afds"
	 *
	 * <?xml version="1.0" encoding="utf-8" ?>
	 * <query-result xmlns="urn:ietf:params:xml:ns:ischedule">
	 *   <capabilities>
	 *     <versions>
	 *       <version>1.0</version>
	 *     </versions>
	 *     <scheduling-messages>
	 *       <component name="VEVENT">
	 *         <method name="REQUEST"/>
	 *         <method name="ADD"/>
	 *         <method name="REPLY"/>
	 *         <method name="CANCEL"/>
	 *       </component>
	 *       <component name="VTODO"/>
	 *       <component name="VFREEBUSY"/>
	 *     </scheduling-messages>
	 *     <calendar-data-types>
	 *       <calendar-data-type content-type="text/calendar" version="2.0"/>
	 *     </calendar-data-types>
	 *     <attachmens>
	 *       <inline/>
	 *       <external/>
	 *     </attachments>
	 *     <supported-recipient-uri-scheme-set>
	 *       <scheme>mailto</scheme>
	 *     </supported-recipient-uri-scheme-set>
	 *     <max-content-length>102400</max-content-length>
	 *     <min-date-time>19910101T000000Z</min-date-time>
	 *     <max-date-time>20381231T000000Z</max-date-time>
	 *     <max-instances>150</max-instances>
	 *     <max-recipients>250</max-recipients>
	 *     <administrator>mailto:ischedule-admin@example.com</administrator>
	 *   </capabilities>
	 * </query-result>
	 */
	protected function get()
	{
		if (!isset($_GET['action']) || $_GET['action'] !== 'capabilities')
		{
			error_log(__METHOD__."() invalid iSchedule request using GET without action=capabilities!");
			header("HTTP/1.1 400 Bad Request");
			return;
		}

		// generate capabilities
		/*$xml = new XMLWriter;
		$xml->openMemory();
		$xml->setIndent(true);
		$xml->startDocument('1.0', 'UTF-8');
		$xml->startElementNs(null, 'query-result', self::ISCHEDULE);
		$xml->startElement('capability-set');

		foreach(array(
			'versions' => array('version' => array('1.0')),
			'scheduling-messages' => array(
				'component' => array('.name' => array(
					'VEVENT' => array('method' => array('REQUEST', 'ADD', 'REPLY', 'CANCEL')),
					'VTODO' => '',
					'VFREEBUSY' => '',
				)),
			)
		) as $name => $data)
		{
			$xml->writeElement($name, $data);
		}

		$xml->endElement();	// capability-set
		$xml->endElement();	// query-result
		$xml->endDocument();
		$capabilities = $xml->outputMemory();*/

		$capabilities = '<?xml version="1.0" encoding="utf-8" ?>
  <query-result xmlns="urn:ietf:params:xml:ns:ischedule">
    <capabilities>
      <versions>
        <version>1.0</version>
      </versions>
      <scheduling-messages>
        <component name="VEVENT">
          <method name="REQUEST"/>
          <method name="ADD"/>
          <method name="REPLY"/>
          <method name="CANCEL"/>
        </component>
        <component name="VTODO"/>
        <component name="VFREEBUSY"/>
      </scheduling-messages>
      <calendar-data-types>
        <calendar-data-type content-type="text/calendar" version="2.0"/>
      </calendar-data-types>
      <attachments>
        <inline/>
        <external/>
      </attachments>
      <supported-recipient-uri-scheme-set>
        <scheme>mailto</scheme>
      </supported-recipient-uri-scheme-set>
      <max-content-length>102400</max-content-length>
      <min-date-time>19910101T000000Z</min-date-time>
      <max-date-time>20381231T000000Z</max-date-time>
      <max-instances>150</max-instances>
      <max-recipients>250</max-recipients>
      <administrator>mailto:ischedule-admin@example.com</administrator>
    </capabilities>
  </query-result>';

		// returning capabilities
		header('Content-Type: application/xml; charset=utf-8');
		header('iSchedule-Version: '.self::VERSION);
		header('Content-Length: '.bytes($capabilites));
		header('ETag: "'.md5($capabilites).'"');

		echo $capabilities;
		common::egw_exit();
	}

	/**
	 * Exception handler, which additionally logs the request (incl. a trace)
	 *
	 * Does NOT return and get installed in constructor.
	 *
	 * @param Exception $e
	 */
	public static function exception_handler(Exception $e)
	{
		// logging exception as regular egw_execption_hander does
		_egw_log_exception($e,$headline);

		// exception handler sending message back to the client as http status
		$code = $e->getCode();
		$msg = $e->getMessage();
		if (!in_array($code, array(400, 403, 407, 503))) $code = 500;
		header('HTTP/1.1 '.$code.' '.$msg);

		// if our groupdav logging is active, log the request plus a trace, if enabled in server-config
		/*if (groupdav::$request_starttime && isset($GLOBALS['groupdav']) && is_a($GLOBALS['groupdav'],'groupdav'))
		{
			$GLOBALS['groupdav']->_http_status = '401 Unauthorized';	// to correctly log it
			if ($GLOBALS['egw_info']['server']['exception_show_trace'])
			{
				$GLOBALS['groupdav']->log_request("\n".$e->getTraceAsString()."\n");
			}
			else
			{
				$GLOBALS['groupdav']->log_request();
			}
		}*/
		if (is_object($GLOBALS['egw']))
		{
			common::egw_exit();
		}
		exit;
	}
}
