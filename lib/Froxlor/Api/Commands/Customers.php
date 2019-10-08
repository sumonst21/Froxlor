<?php
namespace Froxlor\Api\Commands;

use Froxlor\Database\Database;
use Froxlor\Settings;

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Froxlor team <team@froxlor.org> (2010-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package API
 * @since 0.10.0
 *       
 */
class Customers extends \Froxlor\Api\ApiCommand implements \Froxlor\Api\ResourceEntity
{

	/**
	 * lists all customer entries
	 *
	 * @access admin
	 * @throws \Exception
	 * @return string json-encoded array count|list
	 */
	public function listing()
	{
		if ($this->isAdmin()) {
			$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_NOTICE, "[API] list customers");
			$result_stmt = Database::prepare("
				SELECT `c`.*, `a`.`loginname` AS `adminname`
				FROM `" . TABLE_PANEL_CUSTOMERS . "` `c`, `" . TABLE_PANEL_ADMINS . "` `a`
				WHERE " . ($this->getUserDetail('customers_see_all') ? '' : " `c`.`adminid` = :adminid AND ") . "
				`c`.`adminid` = `a`.`adminid`
				ORDER BY `c`.`loginname` ASC
			");
			$params = array();
			if ($this->getUserDetail('customers_see_all') == '0') {
				$params = array(
					'adminid' => $this->getUserDetail('adminid')
				);
			}
			Database::pexecute($result_stmt, $params, true, true);
			$result = array();
			while ($row = $result_stmt->fetch(\PDO::FETCH_ASSOC)) {
				$result[] = $row;
			}
			return $this->response(200, "successfull", array(
				'count' => count($result),
				'list' => $result
			));
		}
		throw new \Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * return a customer entry by either id or loginname
	 *
	 * @param int $id
	 *        	optional, the customer-id
	 * @param string $loginname
	 *        	optional, the loginname
	 *        	
	 * @access admin, customer
	 * @throws \Exception
	 * @return string json-encoded array
	 */
	public function get()
	{
		$id = $this->getParam('id', true, 0);
		$ln_optional = ($id <= 0 ? false : true);
		$loginname = $this->getParam('loginname', $ln_optional, '');

		if ($this->isAdmin()) {
			$result_stmt = Database::prepare("
			SELECT `c`.*, `a`.`loginname` AS `adminname`
			FROM `" . TABLE_PANEL_CUSTOMERS . "` `c`, `" . TABLE_PANEL_ADMINS . "` `a`
			WHERE " . ($id > 0 ? "`c`.`customerid` = :idln" : "`c`.`loginname` = :idln") . ($this->getUserDetail('customers_see_all') ? '' : " AND `c`.`adminid` = :adminid") . " AND `c`.`adminid` = `a`.`adminid`");
			$params = array(
				'idln' => ($id <= 0 ? $loginname : $id)
			);
			if ($this->getUserDetail('customers_see_all') == '0') {
				$params['adminid'] = $this->getUserDetail('adminid');
			}
		} else {
			if (($id > 0 && $id != $this->getUserDetail('customerid')) || ! empty($loginname) && $loginname != $this->getUserDetail('loginname')) {
				throw new \Exception("You cannot access data of other customers", 401);
			}
			$result_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_CUSTOMERS . "`
				WHERE " . ($id > 0 ? "`customerid` = :idln" : "`loginname` = :idln"));
			$params = array(
				'idln' => ($id <= 0 ? $loginname : $id)
			);
		}
		$result = Database::pexecute_first($result_stmt, $params, true, true);
		if ($result) {
			// check whether the admin does not want the customer to see the notes
			if (! $this->isAdmin() && $result['custom_notes_show'] != 1) {
				$result['custom_notes'] = "";
			}
			$this->logger()->logAction($this->isAdmin() ? \Froxlor\FroxlorLogger::ADM_ACTION : \Froxlor\FroxlorLogger::USR_ACTION, LOG_NOTICE, "[API] get customer '" . $result['loginname'] . "'");
			return $this->response(200, "successfull", $result);
		}
		$key = ($id > 0 ? "id #" . $id : "loginname '" . $loginname . "'");
		throw new \Exception("Customer with " . $key . " could not be found", 404);
	}

	/**
	 * create a new customer with default ftp-user and standard-subdomain (if wanted)
	 *
	 * @param string $email
	 * @param string $name
	 *        	optional if company is set, else required
	 * @param string $firstname
	 *        	optional if company is set, else required
	 * @param string $company
	 *        	optional but required if name/firstname empty
	 * @param string $street
	 *        	optional
	 * @param string $zipcode
	 *        	optional
	 * @param string $city
	 *        	optional
	 * @param string $phone
	 *        	optional
	 * @param string $fax
	 *        	optional
	 * @param int $customernumber
	 *        	optional
	 * @param string $def_language,
	 *        	optional, default is system-default language
	 * @param int $gender
	 *        	optional, 0 = no-gender, 1 = male, 2 = female
	 * @param string $custom_notes
	 *        	optional notes
	 * @param bool $custom_notes_show
	 *        	optional, whether to show the content of custom_notes to the customer, default 0 (false)
	 * @param string $new_loginname
	 *        	optional, if empty generated automatically using customer-prefix and increasing number
	 * @param string $password
	 *        	optional, if empty generated automatically and send to the customer's email if $sendpassword is 1
	 * @param bool $sendpassword
	 *        	optional, whether to send the password to the customer after creation, default 0 (false)
	 * @param int $diskspace
	 *        	optional disk-space available for customer in MB, default 0
	 * @param bool $diskspace_ul
	 *        	optional, whether customer should have unlimited diskspace, default 0 (false)
	 * @param int $traffic
	 *        	optional traffic available for customer in GB, default 0
	 * @param bool $traffic_ul
	 *        	optional, whether customer should have unlimited traffic, default 0 (false)
	 * @param int $subdomains
	 *        	optional amount of subdomains available for customer, default 0
	 * @param bool $subdomains_ul
	 *        	optional, whether customer should have unlimited subdomains, default 0 (false)
	 * @param int $emails
	 *        	optional amount of emails available for customer, default 0
	 * @param bool $emails_ul
	 *        	optional, whether customer should have unlimited emails, default 0 (false)
	 * @param int $email_accounts
	 *        	optional amount of email-accounts available for customer, default 0
	 * @param bool $email_accounts_ul
	 *        	optional, whether customer should have unlimited email-accounts, default 0 (false)
	 * @param int $email_forwarders
	 *        	optional amount of email-forwarders available for customer, default 0
	 * @param bool $email_forwarders_ul
	 *        	optional, whether customer should have unlimited email-forwarders, default 0 (false)
	 * @param int $email_quota
	 *        	optional size of email-quota available for customer in MB, default is system-setting mail_quota
	 * @param bool $email_quota_ul
	 *        	optional, whether customer should have unlimited email-quota, default 0 (false)
	 * @param bool $email_imap
	 *        	optional, whether to allow IMAP access, default 0 (false)
	 * @param bool $email_pop3
	 *        	optional, whether to allow POP3 access, default 0 (false)
	 * @param int $ftps
	 *        	optional amount of ftp-accounts available for customer, default 0
	 * @param bool $ftps_ul
	 *        	optional, whether customer should have unlimited ftp-accounts, default 0 (false)
	 * @param int $mysqls
	 *        	optional amount of mysql-databases available for customer, default 0
	 * @param bool $mysqls_ul
	 *        	optional, whether customer should have unlimited mysql-databases, default 0 (false)
	 * @param bool $createstdsubdomain
	 *        	optional, whether to create a standard-subdomain ([loginname].froxlor-hostname.tld), default 0 (false)
	 * @param bool $phpenabled
	 *        	optional, whether to allow usage of PHP, default 0 (false)
	 * @param array $allowed_phpconfigs
	 *        	optional, array of IDs of php-config that the customer is allowed to use, default empty (none)
	 * @param bool $perlenabled
	 *        	optional, whether to allow usage of Perl/CGI, default 0 (false)
	 * @param bool $dnsenabled
	 *        	optional, wether to allow usage of the DNS editor (requires activated nameserver in settings), default 0 (false)
	 * @param bool $logviewenabled
	 *        	optional, wether to allow acccess to webserver access/error-logs, default 0 (false)
	 * @param bool $store_defaultindex
	 *        	optional, whether to store the default index file to customers homedir
	 * @param int $hosting_plan_id
	 *        	optional, specify a hosting-plan to set certain resource-values from the plan instead of specifying them
	 *        	
	 * @access admin
	 * @throws \Exception
	 * @return string json-encoded array
	 */
	public function add()
	{
		if ($this->isAdmin()) {
			if ($this->getUserDetail('customers_used') < $this->getUserDetail('customers') || $this->getUserDetail('customers') == '-1') {

				// required parameters
				$email = $this->getParam('email');

				// parameters
				$name = $this->getParam('name', true, '');
				$firstname = $this->getParam('firstname', true, '');
				$company_required = (! empty($name) && empty($firstname)) || (empty($name) && ! empty($firstname)) || (empty($name) && empty($firstname));
				$company = $this->getParam('company', ($company_required ? false : true), '');
				$street = $this->getParam('street', true, '');
				$zipcode = $this->getParam('zipcode', true, '');
				$city = $this->getParam('city', true, '');
				$phone = $this->getParam('phone', true, '');
				$fax = $this->getParam('fax', true, '');
				$customernumber = $this->getParam('customernumber', true, '');
				$def_language = $this->getParam('def_language', true, Settings::Get('panel.standardlanguage'));
				$gender = (int) $this->getParam('gender', true, 0);
				$custom_notes = $this->getParam('custom_notes', true, '');
				$custom_notes_show = $this->getBoolParam('custom_notes_show', true, 0);
				$createstdsubdomain = $this->getBoolParam('createstdsubdomain', true, 0);
				$password = $this->getParam('new_customer_password', true, '');
				$sendpassword = $this->getBoolParam('sendpassword', true, 0);
				$store_defaultindex = $this->getBoolParam('store_defaultindex', true, 0);
				$loginname = $this->getParam('new_loginname', true, '');

				// hosting-plan values
				$hosting_plan_id = $this->getParam('hosting_plan_id', true, 0);
				if ($hosting_plan_id > 0) {
					$hp_result = $this->apiCall('HostingPlans.get', array(
						'id' => $hosting_plan_id
					));
					$hp_result['value'] = json_decode($hp_result['value'], true);
					foreach ($hp_result['value'] as $index => $value) {
						$hp_result[$index] = $value;
					}
					$diskspace = $hp_result['diskspace'] ?? 0;
					$traffic = $hp_result['traffic'] ?? 0;
					$subdomains = $hp_result['subdomains'] ?? 0;
					$emails = $hp_result['emails'] ?? 0;
					$email_accounts = $hp_result['email_accounts'] ?? 0;
					$email_forwarders = $hp_result['email_forwarders'] ?? 0;
					$email_quota = $hp_result['email_quota'] ?? Settings::Get('system.mail_quota');
					$email_imap = $hp_result['email_imap'] ?? 0;
					$email_pop3 = $hp_result['email_pop3'] ?? 0;
					$ftps = $hp_result['ftps'] ?? 0;
					$mysqls = $hp_result['mysqls'] ?? 0;
					$phpenabled = $hp_result['phpenabled'] ?? 0;
					$p_allowed_phpconfigs = $hp_result['allowed_phpconfigs'] ?? 0;
					$perlenabled = $hp_result['perlenabled'] ?? 0;
					$dnsenabled = $hp_result['dnsenabled'] ?? 0;
					$logviewenabled = $hp_result['logviewenabled'] ?? 0;
				} else {
					$diskspace = $this->getUlParam('diskspace', 'diskspace_ul', true, 0);
					$traffic = $this->getUlParam('traffic', 'traffic_ul', true, 0);
					$subdomains = $this->getUlParam('subdomains', 'subdomains_ul', true, 0);
					$emails = $this->getUlParam('emails', 'emails_ul', true, 0);
					$email_accounts = $this->getUlParam('email_accounts', 'email_accounts_ul', true, 0);
					$email_forwarders = $this->getUlParam('email_forwarders', 'email_forwarders_ul', true, 0);
					$email_quota = $this->getUlParam('email_quota', 'email_quota_ul', true, Settings::Get('system.mail_quota'));
					$email_imap = $this->getBoolParam('email_imap', true, 0);
					$email_pop3 = $this->getBoolParam('email_pop3', true, 0);
					$ftps = $this->getUlParam('ftps', 'ftps_ul', true, 0);
					$mysqls = $this->getUlParam('mysqls', 'mysqls_ul', true, 0);
					$phpenabled = $this->getBoolParam('phpenabled', true, 0);
					$p_allowed_phpconfigs = $this->getParam('allowed_phpconfigs', true, array());
					$perlenabled = $this->getBoolParam('perlenabled', true, 0);
					$dnsenabled = $this->getBoolParam('dnsenabled', true, 0);
					$logviewenabled = $this->getBoolParam('logviewenabled', true, 0);
				}

				// validation
				$name = \Froxlor\Validate\Validate::validate($name, 'name', '', '', array(), true);
				$firstname = \Froxlor\Validate\Validate::validate($firstname, 'first name', '', '', array(), true);
				$company = \Froxlor\Validate\Validate::validate($company, 'company', '', '', array(), true);
				$street = \Froxlor\Validate\Validate::validate($street, 'street', '', '', array(), true);
				$zipcode = \Froxlor\Validate\Validate::validate($zipcode, 'zipcode', '/^[0-9 \-A-Z]*$/', '', array(), true);
				$city = \Froxlor\Validate\Validate::validate($city, 'city', '', '', array(), true);
				$phone = \Froxlor\Validate\Validate::validate($phone, 'phone', '/^[0-9\- \+\(\)\/]*$/', '', array(), true);
				$fax = \Froxlor\Validate\Validate::validate($fax, 'fax', '/^[0-9\- \+\(\)\/]*$/', '', array(), true);
				$idna_convert = new \Froxlor\Idna\IdnaWrapper();
				$email = $idna_convert->encode(\Froxlor\Validate\Validate::validate($email, 'email', '', '', array(), true));
				$customernumber = \Froxlor\Validate\Validate::validate($customernumber, 'customer number', '/^[A-Za-z0-9 \-]*$/Di', '', array(), true);
				$def_language = \Froxlor\Validate\Validate::validate($def_language, 'default language', '', '', array(), true);
				$custom_notes = \Froxlor\Validate\Validate::validate(str_replace("\r\n", "\n", $custom_notes), 'custom_notes', '/^[^\0]*$/', '', array(), true);

				if (Settings::Get('system.mail_quota_enabled') != '1') {
					$email_quota = - 1;
				}

				$password = \Froxlor\Validate\Validate::validate($password, 'password', '', '', array(), true);
				// only check if not empty,
				// cause empty == generate password automatically
				if ($password != '') {
					$password = \Froxlor\System\Crypt::validatePassword($password, true);
				}

				// gender out of range? [0,2]
				if ($gender < 0 || $gender > 2) {
					$gender = 0;
				}

				$allowed_phpconfigs = array();
				if (! empty($p_allowed_phpconfigs) && is_array($p_allowed_phpconfigs)) {
					foreach ($p_allowed_phpconfigs as $allowed_phpconfig) {
						$allowed_phpconfig = intval($allowed_phpconfig);
						$allowed_phpconfigs[] = $allowed_phpconfig;
					}
				}
				$allowed_phpconfigs = array_map('intval', $allowed_phpconfigs);

				$diskspace = $diskspace * 1024;
				$traffic = $traffic * 1024 * 1024;

				if (((($this->getUserDetail('diskspace_used') + $diskspace) > $this->getUserDetail('diskspace')) && ($this->getUserDetail('diskspace') / 1024) != '-1') || ((($this->getUserDetail('mysqls_used') + $mysqls) > $this->getUserDetail('mysqls')) && $this->getUserDetail('mysqls') != '-1') || ((($this->getUserDetail('emails_used') + $emails) > $this->getUserDetail('emails')) && $this->getUserDetail('emails') != '-1') || ((($this->getUserDetail('email_accounts_used') + $email_accounts) > $this->getUserDetail('email_accounts')) && $this->getUserDetail('email_accounts') != '-1') || ((($this->getUserDetail('email_forwarders_used') + $email_forwarders) > $this->getUserDetail('email_forwarders')) && $this->getUserDetail('email_forwarders') != '-1') || ((($this->getUserDetail('email_quota_used') + $email_quota) > $this->getUserDetail('email_quota')) && $this->getUserDetail('email_quota') != '-1' && Settings::Get('system.mail_quota_enabled') == '1') || ((($this->getUserDetail('ftps_used') + $ftps) > $this->getUserDetail('ftps')) && $this->getUserDetail('ftps') != '-1') || ((($this->getUserDetail('subdomains_used') + $subdomains) > $this->getUserDetail('subdomains')) && $this->getUserDetail('subdomains') != '-1') || (($diskspace / 1024) == '-1' && ($this->getUserDetail('diskspace') / 1024) != '-1') || ($mysqls == '-1' && $this->getUserDetail('mysqls') != '-1') || ($emails == '-1' && $this->getUserDetail('emails') != '-1') || ($email_accounts == '-1' && $this->getUserDetail('email_accounts') != '-1') || ($email_forwarders == '-1' && $this->getUserDetail('email_forwarders') != '-1') || ($email_quota == '-1' && $this->getUserDetail('email_quota') != '-1' && Settings::Get('system.mail_quota_enabled') == '1') || ($ftps == '-1' && $this->getUserDetail('ftps') != '-1') || ($subdomains == '-1' && $this->getUserDetail('subdomains') != '-1')) {
					\Froxlor\UI\Response::standard_error('youcantallocatemorethanyouhave', '', true);
				}

				if (! \Froxlor\Validate\Validate::validateEmail($email)) {
					\Froxlor\UI\Response::standard_error('emailiswrong', $email, true);
				} else {

					if ($loginname != '') {
						$accountnumber = intval(Settings::Get('system.lastaccountnumber'));
						$loginname = \Froxlor\Validate\Validate::validate($loginname, 'loginname', '/^[a-z][a-z0-9\-_]+$/i', '', array(), true);

						// Accounts which match systemaccounts are not allowed, filtering them
						if (preg_match('/^' . preg_quote(Settings::Get('customer.accountprefix'), '/') . '([0-9]+)/', $loginname)) {
							\Froxlor\UI\Response::standard_error('loginnameissystemaccount', Settings::Get('customer.accountprefix'), true);
						}

						// Additional filtering for Bug #962
						if (function_exists('posix_getpwnam') && ! in_array("posix_getpwnam", explode(",", ini_get('disable_functions'))) && posix_getpwnam($loginname)) {
							\Froxlor\UI\Response::standard_error('loginnameissystemaccount', Settings::Get('customer.accountprefix'), true);
						}
					} else {
						$accountnumber = intval(Settings::Get('system.lastaccountnumber')) + 1;
						$loginname = Settings::Get('customer.accountprefix') . $accountnumber;
					}

					// Check if the account already exists
					// do not check via api as we skip any permission checks for this task
					$loginname_check_stmt = Database::prepare("
						SELECT `loginname` FROM `" . TABLE_PANEL_CUSTOMERS . "` WHERE `loginname` = :login
					");
					$loginname_check = Database::pexecute_first($loginname_check_stmt, array(
						'login' => $loginname
					), true, true);

					// Check if an admin with the loginname already exists
					// do not check via api as we skip any permission checks for this task
					$loginname_check_admin_stmt = Database::prepare("
						SELECT `loginname` FROM `" . TABLE_PANEL_ADMINS . "` WHERE `loginname` = :login
					");
					$loginname_check_admin = Database::pexecute_first($loginname_check_admin_stmt, array(
						'login' => $loginname
					), true, true);

					$mysql_maxlen = \Froxlor\Database\Database::getSqlUsernameLength() - strlen(Settings::Get('customer.mysqlprefix'));
					if (strtolower($loginname_check['loginname']) == strtolower($loginname) || strtolower($loginname_check_admin['loginname']) == strtolower($loginname)) {
						\Froxlor\UI\Response::standard_error('loginnameexists', $loginname, true);
					} elseif (! \Froxlor\Validate\Validate::validateUsername($loginname, Settings::Get('panel.unix_names'), $mysql_maxlen)) {
						if (strlen($loginname) > $mysql_maxlen) {
							\Froxlor\UI\Response::standard_error('loginnameiswrong2', $mysql_maxlen, true);
						} else {
							\Froxlor\UI\Response::standard_error('loginnameiswrong', $loginname, true);
						}
					}

					$guid = intval(Settings::Get('system.lastguid')) + 1;
					$documentroot = \Froxlor\FileDir::makeCorrectDir(Settings::Get('system.documentroot_prefix') . '/' . $loginname);

					if (file_exists($documentroot)) {
						\Froxlor\UI\Response::standard_error('documentrootexists', $documentroot, true);
					}

					if ($createstdsubdomain != '1') {
						$createstdsubdomain = '0';
					}

					if ($phpenabled != '0') {
						$phpenabled = '1';
					}

					if ($perlenabled != '0') {
						$perlenabled = '1';
					}

					if ($dnsenabled != '0') {
						$dnsenabled = '1';
					}

					if ($logviewenabled != '0') {
						$logviewenabled = '1';
					}

					if ($password == '') {
						$password = \Froxlor\System\Crypt::generatePassword();
					}

					$_theme = Settings::Get('panel.default_theme');

					$ins_data = array(
						'adminid' => $this->getUserDetail('adminid'),
						'loginname' => $loginname,
						'passwd' => \Froxlor\System\Crypt::makeCryptPassword($password),
						'name' => $name,
						'firstname' => $firstname,
						'gender' => $gender,
						'company' => $company,
						'street' => $street,
						'zipcode' => $zipcode,
						'city' => $city,
						'phone' => $phone,
						'fax' => $fax,
						'email' => $email,
						'customerno' => $customernumber,
						'lang' => $def_language,
						'docroot' => $documentroot,
						'guid' => $guid,
						'diskspace' => $diskspace,
						'traffic' => $traffic,
						'subdomains' => $subdomains,
						'emails' => $emails,
						'email_accounts' => $email_accounts,
						'email_forwarders' => $email_forwarders,
						'email_quota' => $email_quota,
						'ftps' => $ftps,
						'mysqls' => $mysqls,
						'phpenabled' => $phpenabled,
						'allowed_phpconfigs' => empty($allowed_phpconfigs) ? "" : json_encode($allowed_phpconfigs),
						'imap' => $email_imap,
						'pop3' => $email_pop3,
						'perlenabled' => $perlenabled,
						'dnsenabled' => $dnsenabled,
						'logviewenabled' => $logviewenabled,
						'theme' => $_theme,
						'custom_notes' => $custom_notes,
						'custom_notes_show' => $custom_notes_show
					);

					$ins_stmt = Database::prepare("
						INSERT INTO `" . TABLE_PANEL_CUSTOMERS . "` SET
						`adminid` = :adminid,
						`loginname` = :loginname,
						`password` = :passwd,
						`name` = :name,
						`firstname` = :firstname,
						`gender` = :gender,
						`company` = :company,
						`street` = :street,
						`zipcode` = :zipcode,
						`city` = :city,
						`phone` = :phone,
						`fax` = :fax,
						`email` = :email,
						`customernumber` = :customerno,
						`def_language` = :lang,
						`documentroot` = :docroot,
						`guid` = :guid,
						`diskspace` = :diskspace,
						`traffic` = :traffic,
						`subdomains` = :subdomains,
						`emails` = :emails,
						`email_accounts` = :email_accounts,
						`email_forwarders` = :email_forwarders,
						`email_quota` = :email_quota,
						`ftps` = :ftps,
						`mysqls` = :mysqls,
						`standardsubdomain` = '0',
						`phpenabled` = :phpenabled,
						`allowed_phpconfigs` = :allowed_phpconfigs,
						`imap` = :imap,
						`pop3` = :pop3,
						`perlenabled` = :perlenabled,
						`dnsenabled` = :dnsenabled,
						`logviewenabled` = :logviewenabled,
						`theme` = :theme,
						`custom_notes` = :custom_notes,
						`custom_notes_show` = :custom_notes_show
					");
					Database::pexecute($ins_stmt, $ins_data, true, true);

					$customerid = Database::lastInsertId();
					$ins_data['customerid'] = $customerid;

					// update admin resource-usage
					if ($mysqls != '-1') {
						Admins::increaseUsage($this->getUserDetail('adminid'), 'mysqls_used', '', (int) $mysqls);
					}

					if ($emails != '-1') {
						Admins::increaseUsage($this->getUserDetail('adminid'), 'emails_used', '', (int) $emails);
					}

					if ($email_accounts != '-1') {
						Admins::increaseUsage($this->getUserDetail('adminid'), 'email_accounts_used', '', (int) $email_accounts);
					}

					if ($email_forwarders != '-1') {
						Admins::increaseUsage($this->getUserDetail('adminid'), 'email_forwarders_used', '', (int) $email_forwarders);
					}

					if ($email_quota != '-1') {
						Admins::increaseUsage($this->getUserDetail('adminid'), 'email_quota_used', '', (int) $email_quota);
					}

					if ($subdomains != '-1') {
						Admins::increaseUsage($this->getUserDetail('adminid'), 'subdomains_used', '', (int) $subdomains);
					}

					if ($ftps != '-1') {
						Admins::increaseUsage($this->getUserDetail('adminid'), 'ftps_used', '', (int) $ftps);
					}

					if (($diskspace / 1024) != '-1') {
						Admins::increaseUsage($this->getUserDetail('adminid'), 'diskspace_used', '', (int) $diskspace);
					}

					// update last guid
					Settings::Set('system.lastguid', $guid, true);

					if ($accountnumber != intval(Settings::Get('system.lastaccountnumber'))) {
						// update last account number
						Settings::Set('system.lastaccountnumber', $accountnumber, true);
					}

					$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_INFO, "[API] added customer '" . $loginname . "'");
					unset($ins_data);

					// insert task to create homedir etc.
					\Froxlor\System\Cronjob::inserttask('2', $loginname, $guid, $guid, $store_defaultindex);

					// Using filesystem - quota, insert a task which cleans the filesystem - quota
					\Froxlor\System\Cronjob::inserttask('10');

					// Add htpasswd for the stats-pages
					$htpasswdPassword = \Froxlor\System\Crypt::makeCryptPassword($password, true);

					$ins_stmt = Database::prepare("
						INSERT INTO `" . TABLE_PANEL_HTPASSWDS . "` SET
						`customerid` = :customerid,
						`username` = :username,
						`password` = :passwd,
						`path` = :path
					");
					$ins_data = array(
						'customerid' => $customerid,
						'username' => $loginname,
						'passwd' => $htpasswdPassword
					);

					$stats_folder = 'webalizer';
					if (Settings::Get('system.awstats_enabled') == '1') {
						$stats_folder = 'awstats';
					}
					$ins_data['path'] = \Froxlor\FileDir::makeCorrectDir($documentroot . '/' . $stats_folder . '/');
					$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_NOTICE, "[API] automatically added " . $stats_folder . " htpasswd for user '" . $loginname . "'");
					Database::pexecute($ins_stmt, $ins_data, true, true);

					\Froxlor\System\Cronjob::inserttask('1');

					// add default FTP-User
					// also, add froxlor-local user to ftp-group (if exists!) to
					// allow access to customer-directories from within the panel, which
					// is necessary when pathedit = Dropdown
					$local_users = array(
						Settings::Get('system.httpuser')
					);
					if ((int) Settings::Get('system.mod_fcgid_ownvhost') == 1 || (int) Settings::Get('phpfpm.enabled_ownvhost') == 1) {
						if ((int) Settings::Get('system.mod_fcgid') == 1) {
							$local_user = Settings::Get('system.mod_fcgid_httpuser');
						} else {
							$local_user = Settings::Get('phpfpm.vhost_httpuser');
						}
						// check froxlor-local user membership in ftp-group
						// without this check addition may duplicate user in list if httpuser == local_user
						if (in_array($local_user, $local_users) == false) {
							$local_users[] = $local_user;
						}
					}
					$this->apiCall('Ftps.add', array(
						'customerid' => $customerid,
						'path' => '/',
						'ftp_password' => $password,
						'ftp_description' => "Default",
						'sendinfomail' => 0,
						'ftp_username' => $loginname,
						'additional_members' => $local_users,
						'is_defaultuser' => 1
					));

					$_stdsubdomain = '';
					if ($createstdsubdomain == '1') {
						if (Settings::Get('system.stdsubdomain') !== null && Settings::Get('system.stdsubdomain') != '') {
							$_stdsubdomain = $loginname . '.' . Settings::Get('system.stdsubdomain');
						} else {
							$_stdsubdomain = $loginname . '.' . Settings::Get('system.hostname');
						}

						$ins_data = array(
							'domain' => $_stdsubdomain,
							'customerid' => $customerid,
							'adminid' => $this->getUserDetail('adminid'),
							'docroot' => $documentroot,
							'phpenabled' => $phpenabled,
							'openbasedir' => '1'
						);
						$domainid = - 1;
						try {
							$std_domain = $this->apiCall('Domains.add', $ins_data);
							$domainid = $std_domain['id'];
						} catch (\Exception $e) {
							$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_ERR, "[API] Unable to add standard-subdomain: " . $e->getMessage());
						}

						if ($domainid > 0) {
							$upd_stmt = Database::prepare("
								UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `standardsubdomain` = :domainid WHERE `customerid` = :customerid
							");
							Database::pexecute($upd_stmt, array(
								'domainid' => $domainid,
								'customerid' => $customerid
							), true, true);
							$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_NOTICE, "[API] automatically added standardsubdomain for user '" . $loginname . "'");
							\Froxlor\System\Cronjob::inserttask('1');
						}
					}

					if ($sendpassword == '1') {

						$srv_hostname = Settings::Get('system.hostname');
						if (Settings::Get('system.froxlordirectlyviahostname') == '0') {
							$srv_hostname .= '/' . basename(\Froxlor\Froxlor::getInstallDir());
						}

						$srv_ip_stmt = Database::prepare("
							SELECT ip, port FROM `" . TABLE_PANEL_IPSANDPORTS . "`
							WHERE `id` = :defaultip
						");
						$default_ips = Settings::Get('system.defaultip');
						$default_ips = explode(',', $default_ips);
						$srv_ip = Database::pexecute_first($srv_ip_stmt, array(
							'defaultip' => reset($default_ips)
						), true, true);

						$replace_arr = array(
							'FIRSTNAME' => $firstname,
							'NAME' => $name,
							'COMPANY' => $company,
							'SALUTATION' => \Froxlor\User::getCorrectUserSalutation(array(
								'firstname' => $firstname,
								'name' => $name,
								'company' => $company
							)),
							'USERNAME' => $loginname,
							'PASSWORD' => $password,
							'SERVER_HOSTNAME' => $srv_hostname,
							'SERVER_IP' => isset($srv_ip['ip']) ? $srv_ip['ip'] : '',
							'SERVER_PORT' => isset($srv_ip['port']) ? $srv_ip['port'] : '',
							'DOMAINNAME' => $_stdsubdomain
						);

						// get template for mail subject
						$mail_subject = $this->getMailTemplate(array(
							'adminid' => $this->getUserDetail('adminid'),
							'def_language' => $def_language
						), 'mails', 'createcustomer_subject', $replace_arr, $this->lng['mails']['createcustomer']['subject']);
						// get template for mail body
						$mail_body = $this->getMailTemplate(array(
							'adminid' => $this->getUserDetail('adminid'),
							'def_language' => $def_language
						), 'mails', 'createcustomer_mailbody', $replace_arr, $this->lng['mails']['createcustomer']['mailbody']);

						$_mailerror = false;
						$mailerr_msg = "";
						try {
							$this->mailer()->Subject = $mail_subject;
							$this->mailer()->AltBody = $mail_body;
							$this->mailer()->msgHTML(str_replace("\n", "<br />", $mail_body));
							$this->mailer()->addAddress($email, \Froxlor\User::getCorrectUserSalutation(array(
								'firstname' => $firstname,
								'name' => $name,
								'company' => $company
							)));
							$this->mailer()->send();
						} catch (\PHPMailer\PHPMailer\Exception $e) {
							$mailerr_msg = $e->errorMessage();
							$_mailerror = true;
						} catch (\Exception $e) {
							$mailerr_msg = $e->getMessage();
							$_mailerror = true;
						}

						if ($_mailerror) {
							$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_ERR, "[API] Error sending mail: " . $mailerr_msg);
							\Froxlor\UI\Response::standard_error('errorsendingmail', $email, true);
						}

						$this->mailer()->clearAddresses();
						$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_NOTICE, "[API] automatically sent password to user '" . $loginname . "'");
					}
				}
				$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_WARNING, "[API] added customer '" . $loginname . "'");

				$result = $this->apiCall('Customers.get', array(
					'loginname' => $loginname
				));
				return $this->response(200, "successfull", $result);
			}
			throw new \Exception("No more resources available", 406);
		}
		throw new \Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * update customer entry by either id or loginname, customer can only change language, password and theme
	 *
	 * @param int $id
	 *        	optional, the customer-id
	 * @param string $loginname
	 *        	optional, the loginname
	 * @param string $email
	 * @param string $name
	 *        	optional if company is set, else required
	 * @param string $firstname
	 *        	optional if company is set, else required
	 * @param string $company
	 *        	optional but required if name/firstname empty
	 * @param string $street
	 *        	optional
	 * @param string $zipcode
	 *        	optional
	 * @param string $city
	 *        	optional
	 * @param string $phone
	 *        	optional
	 * @param string $fax
	 *        	optional
	 * @param int $customernumber
	 *        	optional
	 * @param string $def_language,
	 *        	optional, default is system-default language
	 * @param int $gender
	 *        	optional, 0 = no-gender, 1 = male, 2 = female
	 * @param string $custom_notes
	 *        	optional notes
	 * @param bool $custom_notes_show
	 *        	optional, whether to show the content of custom_notes to the customer, default 0 (false)
	 * @param string $new_customer_password
	 *        	optional, iset new password
	 * @param bool $sendpassword
	 *        	optional, whether to send the password to the customer after creation, default 0 (false)
	 * @param int $move_to_admin
	 *        	optional, if valid admin-id is given here, the customer's admin/reseller can be changed
	 * @param bool $deactivated
	 *        	optional, if 1 (true) the customer can be deactivated/suspended
	 * @param int $diskspace
	 *        	optional disk-space available for customer in MB, default 0
	 * @param bool $diskspace_ul
	 *        	optional, whether customer should have unlimited diskspace, default 0 (false)
	 * @param int $traffic
	 *        	optional traffic available for customer in GB, default 0
	 * @param bool $traffic_ul
	 *        	optional, whether customer should have unlimited traffic, default 0 (false)
	 * @param int $subdomains
	 *        	optional amount of subdomains available for customer, default 0
	 * @param bool $subdomains_ul
	 *        	optional, whether customer should have unlimited subdomains, default 0 (false)
	 * @param int $emails
	 *        	optional amount of emails available for customer, default 0
	 * @param bool $emails_ul
	 *        	optional, whether customer should have unlimited emails, default 0 (false)
	 * @param int $email_accounts
	 *        	optional amount of email-accounts available for customer, default 0
	 * @param bool $email_accounts_ul
	 *        	optional, whether customer should have unlimited email-accounts, default 0 (false)
	 * @param int $email_forwarders
	 *        	optional amount of email-forwarders available for customer, default 0
	 * @param bool $email_forwarders_ul
	 *        	optional, whether customer should have unlimited email-forwarders, default 0 (false)
	 * @param int $email_quota
	 *        	optional size of email-quota available for customer in MB, default is system-setting mail_quota
	 * @param bool $email_quota_ul
	 *        	optional, whether customer should have unlimited email-quota, default 0 (false)
	 * @param bool $email_imap
	 *        	optional, whether to allow IMAP access, default 0 (false)
	 * @param bool $email_pop3
	 *        	optional, whether to allow POP3 access, default 0 (false)
	 * @param int $ftps
	 *        	optional amount of ftp-accounts available for customer, default 0
	 * @param bool $ftps_ul
	 *        	optional, whether customer should have unlimited ftp-accounts, default 0 (false)
	 * @param int $mysqls
	 *        	optional amount of mysql-databases available for customer, default 0
	 * @param bool $mysqls_ul
	 *        	optional, whether customer should have unlimited mysql-databases, default 0 (false)
	 * @param bool $createstdsubdomain
	 *        	optional, whether to create a standard-subdomain ([loginname].froxlor-hostname.tld), default 0 (false)
	 * @param bool $phpenabled
	 *        	optional, whether to allow usage of PHP, default 0 (false)
	 * @param array $allowed_phpconfigs
	 *        	optional, array of IDs of php-config that the customer is allowed to use, default empty (none)
	 * @param bool $perlenabled
	 *        	optional, whether to allow usage of Perl/CGI, default 0 (false)
	 * @param bool $dnsenabled
	 *        	optional, ether to allow usage of the DNS editor (requires activated nameserver in settings), default 0 (false)
	 * @param bool $logviewenabled
	 *        	optional, ether to allow acccess to webserver access/error-logs, default 0 (false)
	 * @param string $theme
	 *        	optional, change theme
	 *        	
	 * @access admin, customer
	 * @throws \Exception
	 * @return string json-encoded array
	 */
	public function update()
	{
		$id = $this->getParam('id', true, 0);
		$ln_optional = ($id <= 0 ? false : true);
		$loginname = $this->getParam('loginname', $ln_optional, '');

		$result = $this->apiCall('Customers.get', array(
			'id' => $id,
			'loginname' => $loginname
		));
		$id = $result['customerid'];

		if ($this->isAdmin()) {
			// parameters
			$move_to_admin = (int) ($this->getParam('move_to_admin', true, 0));

			$idna_convert = new \Froxlor\Idna\IdnaWrapper();
			$email = $this->getParam('email', true, $idna_convert->decode($result['email']));
			$name = $this->getParam('name', true, $result['name']);
			$firstname = $this->getParam('firstname', true, $result['firstname']);
			$company_required = (! empty($name) && empty($firstname)) || (empty($name) && ! empty($firstname)) || (empty($name) && empty($firstname));
			$company = $this->getParam('company', ($company_required ? false : true), $result['company']);
			$street = $this->getParam('street', true, $result['street']);
			$zipcode = $this->getParam('zipcode', true, $result['zipcode']);
			$city = $this->getParam('city', true, $result['city']);
			$phone = $this->getParam('phone', true, $result['phone']);
			$fax = $this->getParam('fax', true, $result['fax']);
			$customernumber = $this->getParam('customernumber', true, $result['customernumber']);
			$def_language = $this->getParam('def_language', true, $result['def_language']);
			$gender = (int) $this->getParam('gender', true, $result['gender']);
			$custom_notes = $this->getParam('custom_notes', true, $result['custom_notes']);
			$custom_notes_show = $this->getBoolParam('custom_notes_show', true, $result['custom_notes_show']);

			$dec_places = Settings::Get('panel.decimal_places');
			$diskspace = $this->getUlParam('diskspace', 'diskspace_ul', true, round($result['diskspace'] / 1024, $dec_places));
			$traffic = $this->getUlParam('traffic', 'traffic_ul', true, round($result['traffic'] / (1024 * 1024), $dec_places));
			$subdomains = $this->getUlParam('subdomains', 'subdomains_ul', true, $result['subdomains']);
			$emails = $this->getUlParam('emails', 'emails_ul', true, $result['emails']);
			$email_accounts = $this->getUlParam('email_accounts', 'email_accounts_ul', true, $result['email_accounts']);
			$email_forwarders = $this->getUlParam('email_forwarders', 'email_forwarders_ul', true, $result['email_forwarders']);
			$email_quota = $this->getUlParam('email_quota', 'email_quota_ul', true, $result['email_quota']);
			$email_imap = $this->getParam('email_imap', true, $result['imap']);
			$email_pop3 = $this->getParam('email_pop3', true, $result['pop3']);
			$ftps = $this->getUlParam('ftps', 'ftps_ul', true, $result['ftps']);
			$mysqls = $this->getUlParam('mysqls', 'mysqls_ul', true, $result['mysqls']);
			$createstdsubdomain = $this->getBoolParam('createstdsubdomain', true, 0);
			$password = $this->getParam('new_customer_password', true, '');
			$phpenabled = $this->getBoolParam('phpenabled', true, $result['phpenabled']);
			$allowed_phpconfigs = $this->getParam('allowed_phpconfigs', true, json_decode($result['allowed_phpconfigs'], true));
			$perlenabled = $this->getBoolParam('perlenabled', true, $result['perlenabled']);
			$dnsenabled = $this->getBoolParam('dnsenabled', true, $result['dnsenabled']);
			$logviewenabled = $this->getBoolParam('logviewenabled', true, $result['logviewenabled']);
			$deactivated = $this->getBoolParam('deactivated', true, $result['deactivated']);
			$theme = $this->getParam('theme', true, $result['theme']);
		} else {
			// allowed parameters
			$def_language = $this->getParam('def_language', true, $result['def_language']);
			$password = $this->getParam('new_customer_password', true, '');
			$theme = $this->getParam('theme', true, $result['theme']);
		}

		// validation
		if ($this->isAdmin()) {
			$idna_convert = new \Froxlor\Idna\IdnaWrapper();
			$name = \Froxlor\Validate\Validate::validate($name, 'name', '', '', array(), true);
			$firstname = \Froxlor\Validate\Validate::validate($firstname, 'first name', '', '', array(), true);
			$company = \Froxlor\Validate\Validate::validate($company, 'company', '', '', array(), true);
			$street = \Froxlor\Validate\Validate::validate($street, 'street', '', '', array(), true);
			$zipcode = \Froxlor\Validate\Validate::validate($zipcode, 'zipcode', '/^[0-9 \-A-Z]*$/', '', array(), true);
			$city = \Froxlor\Validate\Validate::validate($city, 'city', '', '', array(), true);
			$phone = \Froxlor\Validate\Validate::validate($phone, 'phone', '/^[0-9\- \+\(\)\/]*$/', '', array(), true);
			$fax = \Froxlor\Validate\Validate::validate($fax, 'fax', '/^[0-9\- \+\(\)\/]*$/', '', array(), true);
			$email = $idna_convert->encode(\Froxlor\Validate\Validate::validate($email, 'email', '', '', array(), true));
			$customernumber = \Froxlor\Validate\Validate::validate($customernumber, 'customer number', '/^[A-Za-z0-9 \-]*$/Di', '', array(), true);
			$custom_notes = \Froxlor\Validate\Validate::validate(str_replace("\r\n", "\n", $custom_notes), 'custom_notes', '/^[^\0]*$/', '', array(), true);
			if (! empty($allowed_phpconfigs)) {
				$allowed_phpconfigs = array_map('intval', $allowed_phpconfigs);
			}
		}
		$def_language = \Froxlor\Validate\Validate::validate($def_language, 'default language', '', '', array(), true);
		$theme = \Froxlor\Validate\Validate::validate($theme, 'theme', '', '', array(), true);

		if (Settings::Get('system.mail_quota_enabled') != '1') {
			$email_quota = - 1;
		}

		if (empty($theme)) {
			$theme = Settings::Get('panel.default_theme');
		}

		if ($this->isAdmin()) {

			$diskspace = $diskspace * 1024;
			$traffic = $traffic * 1024 * 1024;

			if (((($this->getUserDetail('diskspace_used') + $diskspace - $result['diskspace']) > $this->getUserDetail('diskspace')) && ($this->getUserDetail('diskspace') / 1024) != '-1') || ((($this->getUserDetail('mysqls_used') + $mysqls - $result['mysqls']) > $this->getUserDetail('mysqls')) && $this->getUserDetail('mysqls') != '-1') || ((($this->getUserDetail('emails_used') + $emails - $result['emails']) > $this->getUserDetail('emails')) && $this->getUserDetail('emails') != '-1') || ((($this->getUserDetail('email_accounts_used') + $email_accounts - $result['email_accounts']) > $this->getUserDetail('email_accounts')) && $this->getUserDetail('email_accounts') != '-1') || ((($this->getUserDetail('email_forwarders_used') + $email_forwarders - $result['email_forwarders']) > $this->getUserDetail('email_forwarders')) && $this->getUserDetail('email_forwarders') != '-1') || ((($this->getUserDetail('email_quota_used') + $email_quota - $result['email_quota']) > $this->getUserDetail('email_quota')) && $this->getUserDetail('email_quota') != '-1' && Settings::Get('system.mail_quota_enabled') == '1') || ((($this->getUserDetail('ftps_used') + $ftps - $result['ftps']) > $this->getUserDetail('ftps')) && $this->getUserDetail('ftps') != '-1') || ((($this->getUserDetail('subdomains_used') + $subdomains - $result['subdomains']) > $this->getUserDetail('subdomains')) && $this->getUserDetail('subdomains') != '-1') || (($diskspace / 1024) == '-1' && ($this->getUserDetail('diskspace') / 1024) != '-1') || ($mysqls == '-1' && $this->getUserDetail('mysqls') != '-1') || ($emails == '-1' && $this->getUserDetail('emails') != '-1') || ($email_accounts == '-1' && $this->getUserDetail('email_accounts') != '-1') || ($email_forwarders == '-1' && $this->getUserDetail('email_forwarders') != '-1') || ($email_quota == '-1' && $this->getUserDetail('email_quota') != '-1' && Settings::Get('system.mail_quota_enabled') == '1') || ($ftps == '-1' && $this->getUserDetail('ftps') != '-1') || ($subdomains == '-1' && $this->getUserDetail('subdomains') != '-1')) {
				\Froxlor\UI\Response::standard_error('youcantallocatemorethanyouhave', '', true);
			}

			if ($email == '') {
				\Froxlor\UI\Response::standard_error(array(
					'stringisempty',
					'emailadd'
				), '', true);
			} elseif (! \Froxlor\Validate\Validate::validateEmail($email)) {
				\Froxlor\UI\Response::standard_error('emailiswrong', $email, true);
			}
		}

		if ($password != '') {
			$password = \Froxlor\System\Crypt::validatePassword($password, true);
			$password = \Froxlor\System\Crypt::makeCryptPassword($password);
		} else {
			$password = $result['password'];
		}

		if ($this->isAdmin()) {
			if ($createstdsubdomain != '1') {
				$createstdsubdomain = '0';
			}

			if ($createstdsubdomain == '1' && $result['standardsubdomain'] == '0') {

				if (Settings::Get('system.stdsubdomain') !== null && Settings::Get('system.stdsubdomain') != '') {
					$_stdsubdomain = $result['loginname'] . '.' . Settings::Get('system.stdsubdomain');
				} else {
					$_stdsubdomain = $result['loginname'] . '.' . Settings::Get('system.hostname');
				}

				$ins_data = array(
					'domain' => $_stdsubdomain,
					'customerid' => $result['customerid'],
					'adminid' => $this->getUserDetail('adminid'),
					'docroot' => $result['documentroot'],
					'phpenabled' => $phpenabled,
					'openbasedir' => '1'
				);
				$domainid = - 1;
				try {
					$std_domain = $this->apiCall('Domains.add', $ins_data);
					$domainid = $std_domain['id'];
				} catch (\Exception $e) {
					$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_ERR, "[API] Unable to add standard-subdomain: " . $e->getMessage());
				}

				if ($domainid > 0) {
					$upd_stmt = Database::prepare("
							UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `standardsubdomain` = :domainid WHERE `customerid` = :customerid
						");
					Database::pexecute($upd_stmt, array(
						'domainid' => $domainid,
						'customerid' => $result['customerid']
					), true, true);
					$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_NOTICE, "[API] automatically added standardsubdomain for user '" . $result['loginname'] . "'");
					\Froxlor\System\Cronjob::inserttask('1');
				}
			}

			if ($createstdsubdomain == '0' && $result['standardsubdomain'] != '0') {
				try {
					$std_domain = $this->apiCall('Domains.delete', array(
						'id' => $result['standardsubdomain'],
						'is_stdsubdomain' => 1
					));
				} catch (\Exception $e) {
					$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_ERR, "[API] Unable to delete standard-subdomain: " . $e->getMessage());
				}
				$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_NOTICE, "[API] automatically deleted standardsubdomain for user '" . $result['loginname'] . "'");
				\Froxlor\System\Cronjob::inserttask('1');
			}

			if ($deactivated != '1') {
				$deactivated = '0';
			}

			if ($phpenabled != '0') {
				$phpenabled = '1';
			}

			if ($perlenabled != '0') {
				$perlenabled = '1';
			}

			if ($dnsenabled != '0') {
				$dnsenabled = '1';
			}

			if ($phpenabled != $result['phpenabled'] || $perlenabled != $result['perlenabled']) {
				\Froxlor\System\Cronjob::inserttask('1');
			}

			if ($logviewenabled != '0') {
				$logviewenabled = '1';
			}

			// activate/deactivate customer services
			if ($deactivated != $result['deactivated']) {

				$yesno = ($deactivated ? 'N' : 'Y');
				$pop3 = ($deactivated ? '0' : (int) $result['pop3']);
				$imap = ($deactivated ? '0' : (int) $result['imap']);

				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_MAIL_USERS . "` SET `postfix`= :yesno, `pop3` = :pop3, `imap` = :imap WHERE `customerid` = :customerid
				");
				Database::pexecute($upd_stmt, array(
					'yesno' => $yesno,
					'pop3' => $pop3,
					'imap' => $imap,
					'customerid' => $id
				));

				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_FTP_USERS . "` SET `login_enabled` = :yesno WHERE `customerid` = :customerid
				");
				Database::pexecute($upd_stmt, array(
					'yesno' => $yesno,
					'customerid' => $id
				));

				$upd_stmt = Database::prepare("
							UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `deactivated`= :deactivated WHERE `customerid` = :customerid");
				Database::pexecute($upd_stmt, array(
					'deactivated' => $deactivated,
					'customerid' => $id
				));

				// Retrieve customer's databases
				$databases_stmt = Database::prepare("SELECT * FROM " . TABLE_PANEL_DATABASES . " WHERE customerid = :customerid ORDER BY `dbserver`");
				Database::pexecute($databases_stmt, array(
					'customerid' => $id
				));

				Database::needRoot(true);
				$last_dbserver = 0;

				$dbm = new \Froxlor\Database\DbManager($this->logger());

				// For each of them
				$priv_changed = false;
				while ($row_database = $databases_stmt->fetch(\PDO::FETCH_ASSOC)) {

					if ($last_dbserver != $row_database['dbserver']) {
						$dbm->getManager()->flushPrivileges();
						Database::needRoot(true, $row_database['dbserver']);
						$last_dbserver = $row_database['dbserver'];
					}

					foreach (array_unique(explode(',', Settings::Get('system.mysql_access_host'))) as $mysql_access_host) {
						$mysql_access_host = trim($mysql_access_host);

						// Prevent access, if deactivated
						if ($deactivated) {
							// failsafe if user has been deleted manually (requires MySQL 4.1.2+)
							$dbm->getManager()->disableUser($row_database['databasename'], $mysql_access_host);
						} else {
							// Otherwise grant access
							$dbm->getManager()->enableUser($row_database['databasename'], $mysql_access_host);
						}
					}
					$priv_changed = true;
				}

				// At last flush the new privileges
				if ($priv_changed) {
					$dbm->getManager()->flushPrivileges();
				}
				Database::needRoot(false);

				// reactivate/deactivate api-keys
				$valid_until = $deactivated ? 0 : - 1;
				$stmt = Database::prepare("UPDATE `" . TABLE_API_KEYS . "` SET `valid_until` = :vu WHERE `customerid` = :id");
				Database::pexecute($stmt, array(
					'id' => $id,
					'vu' => $valid_until
				), true, true);

				$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_INFO, "[API] " . ($deactivated ? 'deactivated' : 'reactivated') . " user '" . $result['loginname'] . "'");
				\Froxlor\System\Cronjob::inserttask('1');
			}

			// Disable or enable POP3 Login for customers Mail Accounts
			if ($email_pop3 != $result['pop3']) {
				$upd_stmt = Database::prepare("UPDATE `" . TABLE_MAIL_USERS . "` SET `pop3` = :pop3 WHERE `customerid` = :customerid");
				Database::pexecute($upd_stmt, array(
					'pop3' => $email_pop3,
					'customerid' => $id
				));
			}

			// Disable or enable IMAP Login for customers Mail Accounts
			if ($email_imap != $result['imap']) {
				$upd_stmt = Database::prepare("UPDATE `" . TABLE_MAIL_USERS . "` SET `imap` = :imap WHERE `customerid` = :customerid");
				Database::pexecute($upd_stmt, array(
					'imap' => $email_imap,
					'customerid' => $id
				));
			}
		}

		$upd_data = array(
			'customerid' => $id,
			'passwd' => $password,
			'lang' => $def_language,
			'theme' => $theme
		);

		if ($this->isAdmin()) {
			$admin_upd_data = array(
				'name' => $name,
				'firstname' => $firstname,
				'gender' => $gender,
				'company' => $company,
				'street' => $street,
				'zipcode' => $zipcode,
				'city' => $city,
				'phone' => $phone,
				'fax' => $fax,
				'email' => $email,
				'customerno' => $customernumber,
				'diskspace' => $diskspace,
				'traffic' => $traffic,
				'subdomains' => $subdomains,
				'emails' => $emails,
				'email_accounts' => $email_accounts,
				'email_forwarders' => $email_forwarders,
				'email_quota' => $email_quota,
				'ftps' => $ftps,
				'mysqls' => $mysqls,
				'deactivated' => $deactivated,
				'phpenabled' => $phpenabled,
				'allowed_phpconfigs' => empty($allowed_phpconfigs) ? "" : json_encode($allowed_phpconfigs),
				'imap' => $email_imap,
				'pop3' => $email_pop3,
				'perlenabled' => $perlenabled,
				'dnsenabled' => $dnsenabled,
				'logviewenabled' => $logviewenabled,
				'custom_notes' => $custom_notes,
				'custom_notes_show' => $custom_notes_show
			);
			$upd_data = $upd_data + $admin_upd_data;
		}

		$upd_query = "UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET
				`def_language` = :lang,
				`password` = :passwd,
				`theme` = :theme";

		if ($this->isAdmin()) {
			$admin_upd_query = ",
				`name` = :name,
				`firstname` = :firstname,
				`gender` = :gender,
				`company` = :company,
				`street` = :street,
				`zipcode` = :zipcode,
				`city` = :city,
				`phone` = :phone,
				`fax` = :fax,
				`email` = :email,
				`customernumber` = :customerno,
				`diskspace` = :diskspace,
				`traffic` = :traffic,
				`subdomains` = :subdomains,
				`emails` = :emails,
				`email_accounts` = :email_accounts,
				`email_forwarders` = :email_forwarders,
				`ftps` = :ftps,
				`mysqls` = :mysqls,
				`deactivated` = :deactivated,
				`phpenabled` = :phpenabled,
				`allowed_phpconfigs` = :allowed_phpconfigs,
				`email_quota` = :email_quota,
				`imap` = :imap,
				`pop3` = :pop3,
				`perlenabled` = :perlenabled,
				`dnsenabled` = :dnsenabled,
				`logviewenabled` = :logviewenabled,
				`custom_notes` = :custom_notes,
				`custom_notes_show` = :custom_notes_show";
			$upd_query .= $admin_upd_query;
		}
		$upd_query .= " WHERE `customerid` = :customerid";
		$upd_stmt = Database::prepare($upd_query);
		Database::pexecute($upd_stmt, $upd_data);

		if ($this->isAdmin()) {
			// Using filesystem - quota, insert a task which cleans the filesystem - quota
			\Froxlor\System\Cronjob::inserttask('10');

			$admin_update_query = "UPDATE `" . TABLE_PANEL_ADMINS . "` SET `customers_used` = `customers_used` ";

			if ($mysqls != '-1' || $result['mysqls'] != '-1') {
				$admin_update_query .= ", `mysqls_used` = `mysqls_used` ";

				if ($mysqls != '-1') {
					$admin_update_query .= " + 0" . (int) $mysqls . " ";
				}
				if ($result['mysqls'] != '-1') {
					$admin_update_query .= " - 0" . (int) $result['mysqls'] . " ";
				}
			}

			if ($emails != '-1' || $result['emails'] != '-1') {
				$admin_update_query .= ", `emails_used` = `emails_used` ";

				if ($emails != '-1') {
					$admin_update_query .= " + 0" . (int) $emails . " ";
				}
				if ($result['emails'] != '-1') {
					$admin_update_query .= " - 0" . (int) $result['emails'] . " ";
				}
			}

			if ($email_accounts != '-1' || $result['email_accounts'] != '-1') {
				$admin_update_query .= ", `email_accounts_used` = `email_accounts_used` ";

				if ($email_accounts != '-1') {
					$admin_update_query .= " + 0" . (int) $email_accounts . " ";
				}
				if ($result['email_accounts'] != '-1') {
					$admin_update_query .= " - 0" . (int) $result['email_accounts'] . " ";
				}
			}

			if ($email_forwarders != '-1' || $result['email_forwarders'] != '-1') {
				$admin_update_query .= ", `email_forwarders_used` = `email_forwarders_used` ";

				if ($email_forwarders != '-1') {
					$admin_update_query .= " + 0" . (int) $email_forwarders . " ";
				}
				if ($result['email_forwarders'] != '-1') {
					$admin_update_query .= " - 0" . (int) $result['email_forwarders'] . " ";
				}
			}

			if ($email_quota != '-1' || $result['email_quota'] != '-1') {
				$admin_update_query .= ", `email_quota_used` = `email_quota_used` ";

				if ($email_quota != '-1') {
					$admin_update_query .= " + 0" . (int) $email_quota . " ";
				}
				if ($result['email_quota'] != '-1') {
					$admin_update_query .= " - 0" . (int) $result['email_quota'] . " ";
				}
			}

			if ($subdomains != '-1' || $result['subdomains'] != '-1') {
				$admin_update_query .= ", `subdomains_used` = `subdomains_used` ";

				if ($subdomains != '-1') {
					$admin_update_query .= " + 0" . (int) $subdomains . " ";
				}
				if ($result['subdomains'] != '-1') {
					$admin_update_query .= " - 0" . (int) $result['subdomains'] . " ";
				}
			}

			if ($ftps != '-1' || $result['ftps'] != '-1') {
				$admin_update_query .= ", `ftps_used` = `ftps_used` ";

				if ($ftps != '-1') {
					$admin_update_query .= " + 0" . (int) $ftps . " ";
				}
				if ($result['ftps'] != '-1') {
					$admin_update_query .= " - 0" . (int) $result['ftps'] . " ";
				}
			}

			if (($diskspace / 1024) != '-1' || ($result['diskspace'] / 1024) != '-1') {
				$admin_update_query .= ", `diskspace_used` = `diskspace_used` ";

				if (($diskspace / 1024) != '-1') {
					$admin_update_query .= " + 0" . (int) $diskspace . " ";
				}
				if (($result['diskspace'] / 1024) != '-1') {
					$admin_update_query .= " - 0" . (int) $result['diskspace'] . " ";
				}
			}

			$admin_update_query .= " WHERE `adminid` = '" . (int) $result['adminid'] . "'";
			Database::query($admin_update_query);
		}

		$this->logger()->logAction($this->isAdmin() ? \Froxlor\FroxlorLogger::ADM_ACTION : \Froxlor\FroxlorLogger::USR_ACTION, LOG_INFO, "[API] edited user '" . $result['loginname'] . "'");

		/*
		 * move customer to another admin/reseller; #1166
		 */
		if ($this->isAdmin()) {
			if ($move_to_admin > 0 && $move_to_admin != $result['adminid']) {
				$move_result = $this->apiCall('Customers.move', array(
					'id' => $result['customerid'],
					'adminid' => $move_to_admin
				));
				if ($move_result != true) {
					\Froxlor\UI\Response::standard_error('moveofcustomerfailed', $move_result, true);
				}
			}
		}

		$result = $this->apiCall('Customers.get', array(
			'id' => $result['customerid']
		));
		return $this->response(200, "successfull", $result);
	}

	/**
	 * delete a customer entry by either id or loginname
	 *
	 * @param int $id
	 *        	optional, the customer-id
	 * @param string $loginname
	 *        	optional, the loginname
	 * @param bool $delete_userfiles
	 *        	optional, default false
	 *        	
	 * @access admin
	 * @throws \Exception
	 * @return string json-encoded array
	 */
	public function delete()
	{
		if ($this->isAdmin()) {
			$id = $this->getParam('id', true, 0);
			$ln_optional = ($id <= 0 ? false : true);
			$loginname = $this->getParam('loginname', $ln_optional, '');
			$delete_userfiles = $this->getParam('delete_userfiles', true, 0);

			$result = $this->apiCall('Customers.get', array(
				'id' => $id,
				'loginname' => $loginname
			));
			$id = $result['customerid'];

			$databases_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_DATABASES . "`
				WHERE `customerid` = :id ORDER BY `dbserver`
			");
			Database::pexecute($databases_stmt, array(
				'id' => $id
			));
			Database::needRoot(true);
			$last_dbserver = 0;

			$dbm = new \Froxlor\Database\DbManager($this->logger());

			$priv_changed = false;
			while ($row_database = $databases_stmt->fetch(\PDO::FETCH_ASSOC)) {
				if ($last_dbserver != $row_database['dbserver']) {
					Database::needRoot(true, $row_database['dbserver']);
					$dbm->getManager()->flushPrivileges();
					$last_dbserver = $row_database['dbserver'];
				}
				$dbm->getManager()->deleteDatabase($row_database['databasename']);
				$priv_changed = true;
			}
			if ($priv_changed) {
				$dbm->getManager()->flushPrivileges();
			}
			Database::needRoot(false);

			// delete customer itself
			$stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_CUSTOMERS . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// delete customer databases
			$stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_DATABASES . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// first gather all domain-id's to clean up panel_domaintoip and dns-entries accordingly
			$did_stmt = Database::prepare("SELECT `id` FROM `" . TABLE_PANEL_DOMAINS . "` WHERE `customerid` = :id");
			Database::pexecute($did_stmt, array(
				'id' => $id
			), true, true);
			while ($row = $did_stmt->fetch(\PDO::FETCH_ASSOC)) {
				// remove domain->ip connection
				$stmt = Database::prepare("DELETE FROM `" . TABLE_DOMAINTOIP . "` WHERE `id_domain` = :did");
				Database::pexecute($stmt, array(
					'did' => $row['id']
				), true, true);
				// remove domain->dns entries
				$stmt = Database::prepare("DELETE FROM `" . TABLE_DOMAIN_DNS . "` WHERE `domain_id` = :did");
				Database::pexecute($stmt, array(
					'did' => $row['id']
				), true, true);
			}
			// remove customer domains
			$stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_DOMAINS . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);
			$domains_deleted = $stmt->rowCount();

			// delete htpasswds
			$stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_HTPASSWDS . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// delete htaccess options
			$stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_HTACCESS . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// delete potential existing sessions
			$stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_SESSIONS . "` WHERE `userid` = :id AND `adminsession` = '0'");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// delete traffic information
			$stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_TRAFFIC . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// remove diskspace analysis
			$stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_DISKSPACE . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// delete mail-accounts
			$stmt = Database::prepare("DELETE FROM `" . TABLE_MAIL_USERS . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// delete mail-addresses
			$stmt = Database::prepare("DELETE FROM `" . TABLE_MAIL_VIRTUAL . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// gather ftp-user names
			$result2_stmt = Database::prepare("SELECT `username` FROM `" . TABLE_FTP_USERS . "` WHERE `customerid` = :id");
			Database::pexecute($result2_stmt, array(
				'id' => $id
			), true, true);
			while ($row = $result2_stmt->fetch(\PDO::FETCH_ASSOC)) {
				// delete ftp-quotatallies by username
				$stmt = Database::prepare("DELETE FROM `" . TABLE_FTP_QUOTATALLIES . "` WHERE `name` = :name");
				Database::pexecute($stmt, array(
					'name' => $row['username']
				), true, true);
			}

			// remove ftp-group
			$stmt = Database::prepare("DELETE FROM `" . TABLE_FTP_GROUPS . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// remove ftp-users
			$stmt = Database::prepare("DELETE FROM `" . TABLE_FTP_USERS . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// remove api-keys
			$stmt = Database::prepare("DELETE FROM `" . TABLE_API_KEYS . "` WHERE `customerid` = :id");
			Database::pexecute($stmt, array(
				'id' => $id
			), true, true);

			// Delete all waiting "create user" -tasks for this user, #276
			// Note: the WHERE selects part of a serialized array, but it should be safe this way
			$del_stmt = Database::prepare("
				DELETE FROM `" . TABLE_PANEL_TASKS . "`
				WHERE `type` = '2' AND `data` LIKE :loginname
			");
			Database::pexecute($del_stmt, array(
				'loginname' => "%:{$result['loginname']};%"
			), true, true);

			// update admin-resource-usage
			Admins::decreaseUsage($this->getUserDetail('adminid'), 'customers_used');
			Admins::decreaseUsage($this->getUserDetail('adminid'), 'domains_used', '', (int) ($domains_deleted - $result['subdomains_used']));

			if ($result['mysqls'] != '-1') {
				Admins::decreaseUsage($this->getUserDetail('adminid'), 'mysqls_used', '', (int) $result['mysqls']);
			}

			if ($result['emails'] != '-1') {
				Admins::decreaseUsage($this->getUserDetail('adminid'), 'emails_used', '', (int) $result['emails']);
			}

			if ($result['email_accounts'] != '-1') {
				Admins::decreaseUsage($this->getUserDetail('adminid'), 'email_accounts_used', '', (int) $result['email_accounts']);
			}

			if ($result['email_forwarders'] != '-1') {
				Admins::decreaseUsage($this->getUserDetail('adminid'), 'email_forwarders_used', '', (int) $result['email_forwarders']);
			}

			if ($result['email_quota'] != '-1') {
				Admins::decreaseUsage($this->getUserDetail('adminid'), 'email_quota_used', '', (int) $result['email_quota']);
			}

			if ($result['subdomains'] != '-1') {
				Admins::decreaseUsage($this->getUserDetail('adminid'), 'subdomains_used', '', (int) $result['subdomains']);
			}

			if ($result['ftps'] != '-1') {
				Admins::decreaseUsage($this->getUserDetail('adminid'), 'ftps_used', '', (int) $result['ftps']);
			}

			if (($result['diskspace'] / 1024) != '-1') {
				Admins::decreaseUsage($this->getUserDetail('adminid'), 'diskspace_used', '', (int) $result['diskspace']);
			}

			// rebuild configs
			\Froxlor\System\Cronjob::inserttask('1');

			// Using nameserver, insert a task which rebuilds the server config
			\Froxlor\System\Cronjob::inserttask('4');

			if ($delete_userfiles == 1) {
				// insert task to remove the customers files from the filesystem
				\Froxlor\System\Cronjob::inserttask('6', $result['loginname']);
			}

			// Using filesystem - quota, insert a task which cleans the filesystem - quota
			\Froxlor\System\Cronjob::inserttask('10');

			$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_WARNING, "[API] deleted customer '" . $result['loginname'] . "'");
			return $this->response(200, "successfull", $result);
		}
		throw new \Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * unlock a locked customer by either id or loginname
	 *
	 * @param int $id
	 *        	optional, the customer-id
	 * @param string $loginname
	 *        	optional, the loginname
	 *        	
	 * @access admin
	 * @throws \Exception
	 * @return string json-encoded array
	 */
	public function unlock()
	{
		if ($this->isAdmin()) {
			$id = $this->getParam('id', true, 0);
			$ln_optional = ($id <= 0 ? false : true);
			$loginname = $this->getParam('loginname', $ln_optional, '');

			$result = $this->apiCall('Customers.get', array(
				'id' => $id,
				'loginname' => $loginname
			));
			$id = $result['customerid'];

			$result_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET
				`loginfail_count` = '0'
				WHERE `customerid`= :id
			");
			Database::pexecute($result_stmt, array(
				'id' => $id
			), true, true);
			// set the new value for result-array
			$result['loginfail_count'] = 0;

			$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_WARNING, "[API] unlocked customer '" . $result['loginname'] . "'");
			return $this->response(200, "successfull", $result);
		}
		throw new \Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * Function to move a given customer to a given admin/reseller
	 * and update all its references accordingly
	 *
	 * @param int $id
	 *        	optional, the customer-id
	 * @param string $loginname
	 *        	optional, the loginname
	 * @param int $adminid
	 *        	target-admin-id
	 *        	
	 * @access admin
	 * @throws \Exception
	 * @return string json-encoded array
	 */
	public function move()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings') == 1) {
			$adminid = $this->getParam('adminid');
			$id = $this->getParam('id', true, 0);
			$ln_optional = ($id <= 0 ? false : true);
			$loginname = $this->getParam('loginname', $ln_optional, '');

			$c_result = $this->apiCall('Customers.get', array(
				'id' => $id,
				'loginname' => $loginname
			));
			$id = $c_result['customerid'];

			// check if target-admin is the current admin
			if ($adminid == $c_result['adminid']) {
				throw new \Exception("Cannot move customer to the same admin/reseller as he currently is assigned to", 406);
			}

			// get target admin
			$a_result = $this->apiCall('Admins.get', array(
				'id' => $adminid
			));

			// Update customer entry
			$updCustomer_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `adminid` = :adminid WHERE `customerid` = :cid
			");
			Database::pexecute($updCustomer_stmt, array(
				'adminid' => $adminid,
				'cid' => $id
			), true, true);

			// Update customer-domains
			$updDomains_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `adminid` = :adminid WHERE `customerid` = :cid
			");
			Database::pexecute($updDomains_stmt, array(
				'adminid' => $adminid,
				'cid' => $id
			), true, true);

			// now, recalculate the resource-usage for the old and the new admin
			\Froxlor\User::updateCounters(false);

			$this->logger()->logAction(\Froxlor\FroxlorLogger::ADM_ACTION, LOG_INFO, "[API] moved user '" . $c_result['loginname'] . "' from admin/reseller '" . $c_result['adminname'] . " to admin/reseller '" . $a_result['loginname'] . "'");

			$result = $this->apiCall('Customers.get', array(
				'id' => $c_result['customerid']
			));
			return $this->response(200, "successfull", $result);
		}
		throw new \Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * increase resource-usage
	 *
	 * @param int $customerid
	 * @param string $resource
	 * @param string $extra
	 *        	optional, default empty
	 * @param int $increase_by
	 *        	optional, default 1
	 */
	public static function increaseUsage($customerid = 0, $resource = null, $extra = '', $increase_by = 1)
	{
		self::updateResourceUsage(TABLE_PANEL_CUSTOMERS, 'customerid', $customerid, '+', $resource, $extra, $increase_by);
	}

	/**
	 * decrease resource-usage
	 *
	 * @param int $customerid
	 * @param string $resource
	 * @param string $extra
	 *        	optional, default empty
	 * @param int $decrease_by
	 *        	optional, default 1
	 */
	public static function decreaseUsage($customerid = 0, $resource = null, $extra = '', $decrease_by = 1)
	{
		self::updateResourceUsage(TABLE_PANEL_CUSTOMERS, 'customerid', $customerid, '-', $resource, $extra, $decrease_by);
	}
}
