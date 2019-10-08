<?php
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
 * @package Install
 *         
 */
if (! defined('_CRON_UPDATE')) {
	if (! defined('AREA') || (defined('AREA') && AREA != 'admin') || ! isset($userinfo['loginname']) || (isset($userinfo['loginname']) && $userinfo['loginname'] == '')) {
		header('Location: ../../../../index.php');
		exit();
	}
}

if (\Froxlor\Froxlor::isFroxlorVersion('0.9.40.1')) {
	showUpdateStep("Updating from 0.9.40.1 to 0.10.0-rc1", false);

	showUpdateStep("Adding new api keys table");
	Database::query("DROP TABLE IF EXISTS `api_keys`;");
	$sql = "CREATE TABLE `api_keys` (
	  `id` int(11) NOT NULL auto_increment,
	  `adminid` int(11) NOT NULL default '0',
	  `customerid` int(11) NOT NULL default '0',
	  `apikey` varchar(500) NOT NULL default '',
	  `secret` varchar(500) NOT NULL default '',
	  `allowed_from` text NOT NULL,
	  `valid_until` int(15) NOT NULL default '0',
	  PRIMARY KEY  (id),
	  KEY adminid (adminid),
	  KEY customerid (customerid)
	) ENGINE=MyISAM CHARSET=utf8 COLLATE=utf8_general_ci;";
	Database::query($sql);
	lastStepStatus(0);

	showUpdateStep("Adding new api settings");
	Settings::AddNew('api.enabled', 0);
	lastStepStatus(0);

	showUpdateStep("Adding new default-ssl-ip setting");
	Settings::AddNew('system.defaultsslip', '');
	lastStepStatus(0);

	showUpdateStep("Altering admin ip's field to allow multiple ip addresses");
	// get all admins for updating the new field
	$sel_stmt = Database::prepare("SELECT adminid, ip FROM `panel_admins`");
	Database::pexecute($sel_stmt);
	$all_admins = $sel_stmt->fetchAll(PDO::FETCH_ASSOC);
	Database::query("ALTER TABLE `panel_admins` MODIFY `ip` varchar(500) NOT NULL default '-1';");
	$upd_stmt = Database::prepare("UPDATE `panel_admins` SET `ip` = :ip WHERE `adminid` = :adminid");
	foreach ($all_admins as $adm) {
		if ($adm['ip'] != '-1') {
			Database::pexecute($upd_stmt, array(
				'ip' => json_encode($adm['ip']),
				'adminid' => $adm['adminid']
			));
		}
	}
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToVersion('0.10.0-rc1');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201809280')) {

	showUpdateStep("Adding dhparams-file setting");
	Settings::AddNew("system.dhparams_file", '');
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201811180');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201811180')) {

	showUpdateStep("Adding new settings for 2FA");
	Settings::AddNew('2fa.enabled', '1', true);
	lastStepStatus(0);

	showUpdateStep("Adding new fields to admin-table for 2FA");
	Database::query("ALTER TABLE `" . TABLE_PANEL_ADMINS . "` ADD `type_2fa` tinyint(1) NOT NULL default '0';");
	Database::query("ALTER TABLE `" . TABLE_PANEL_ADMINS . "` ADD `data_2fa` varchar(500) NOT NULL default '' AFTER `type_2fa`;");
	lastStepStatus(0);

	showUpdateStep("Adding new fields to customer-table for 2FA");
	Database::query("ALTER TABLE `" . TABLE_PANEL_CUSTOMERS . "` ADD `type_2fa` tinyint(1) NOT NULL default '0';");
	Database::query("ALTER TABLE `" . TABLE_PANEL_CUSTOMERS . "` ADD `data_2fa` varchar(500) NOT NULL default '' AFTER `type_2fa`;");
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201811300');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201811300')) {

	showUpdateStep("Adding new logview-flag to customers");
	Database::query("ALTER TABLE `" . TABLE_PANEL_CUSTOMERS . "` ADD `logviewenabled` tinyint(1) NOT NULL default '0';");
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201812010');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201812010')) {

	showUpdateStep("Adding new is_configured-flag");
	// updated systems are already configured (most likely :P)
	Settings::AddNew('panel.is_configured', '1', true);
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201812100');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201812100')) {

	showUpdateStep("Adding fields writeaccesslog and writeerrorlog for domains");
	Database::query("ALTER TABLE `" . TABLE_PANEL_DOMAINS . "` ADD `writeaccesslog` tinyint(1) NOT NULL default '1';");
	Database::query("ALTER TABLE `" . TABLE_PANEL_DOMAINS . "` ADD `writeerrorlog` tinyint(1) NOT NULL default '1';");
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201812180');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201812180')) {

	showUpdateStep("Updating cronjob table");
	Database::query("ALTER TABLE `" . TABLE_PANEL_CRONRUNS . "` ADD `cronclass` varchar(500) NOT NULL AFTER `cronfile`");
	$upd_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_CRONRUNS . "` SET `cronclass`  = :cc WHERE `cronfile` = :cf");
	Database::pexecute($upd_stmt, array(
		'cc' => '\\Froxlor\\Cron\\System\\TasksCron',
		'cf' => 'tasks'
	));
	Database::pexecute($upd_stmt, array(
		'cc' => '\\Froxlor\\Cron\\Traffic\\TrafficCron',
		'cf' => 'traffic'
	));
	Database::pexecute($upd_stmt, array(
		'cc' => '\\Froxlor\\Cron\\Traffic\\ReportsCron',
		'cf' => 'usage_report'
	));
	Database::pexecute($upd_stmt, array(
		'cc' => '\\Froxlor\\Cron\\System\\MailboxsizeCron',
		'cf' => 'mailboxsize'
	));
	Database::pexecute($upd_stmt, array(
		'cc' => '\\Froxlor\\Cron\\Http\\LetsEncrypt\\LetsEncrypt',
		'cf' => 'letsencrypt'
	));
	Database::pexecute($upd_stmt, array(
		'cc' => '\\Froxlor\\Cron\\System\\BackupCron',
		'cf' => 'backup'
	));
	Database::query("DELETE FROM `" . TABLE_PANEL_CRONRUNS . "` WHERE `module` = 'froxlor/ticket'");
	lastStepStatus(0);

	showUpdateStep("Removing ticketsystem");
	Database::query("ALTER TABLE `" . TABLE_PANEL_ADMINS . "` DROP `tickets`");
	Database::query("ALTER TABLE `" . TABLE_PANEL_ADMINS . "` DROP `tickets_used`");
	Database::query("ALTER TABLE `" . TABLE_PANEL_ADMINS . "` DROP `tickets_see_all`");
	Database::query("ALTER TABLE `" . TABLE_PANEL_CUSTOMERS . "` DROP `tickets`");
	Database::query("ALTER TABLE `" . TABLE_PANEL_CUSTOMERS . "` DROP `tickets_used`");
	Database::query("DELETE FROM `" . TABLE_PANEL_SETTINGS . "` WHERE `settinggroup` = 'ticket'");

	define('TABLE_PANEL_TICKETS', 'panel_tickets');
	define('TABLE_PANEL_TICKET_CATS', 'panel_ticket_categories');
	Database::query("DROP TABLE IF EXISTS `" . TABLE_PANEL_TICKETS . "`;");
	Database::query("DROP TABLE IF EXISTS `" . TABLE_PANEL_TICKET_CATS . "`;");
	lastStepStatus(0);

	showUpdateStep("Updating nameserver settings");
	$dns_target = 'Bind';
	if (Settings::Get('system.dns_server') != 'bind') {
		$dns_target = 'PowerDNS';
	}
	$upd_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_SETTINGS . "` SET `value`  = :v WHERE `settinggroup` = 'system' AND `varname` = 'dns_server'");
	Database::pexecute($upd_stmt, array(
		'v' => $dns_target
	));
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201812190');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201812190')) {

	showUpdateStep("Adding new webserver error-log-level setting");
	Settings::AddNew('system.errorlog_level', (\Froxlor\Settings::Get('system.webserver') == 'nginx' ? 'error' : 'warn'));
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201902120');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201902120')) {

	showUpdateStep("Adding new ECC / ECDSA setting for Let's Encrypt");
	Settings::AddNew('system.leecc', '0');
	$upd_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_CRONRUNS . "` SET `cronclass`  = :cc WHERE `cronfile` = :cf");
	Database::pexecute($upd_stmt, array(
		'cc' => '\\Froxlor\\Cron\\Http\\LetsEncrypt\\AcmeSh',
		'cf' => 'letsencrypt'
	));
	Settings::Set('system.letsencryptkeysize', '4096', true);
	lastStepStatus(0);

	showUpdateStep("Removing current Let's Encrypt certificates due to new implementation of acme.sh");
	$sel_result = Database::query("SELECT id FROM `" . TABLE_PANEL_DOMAINS . "` WHERE `letsencrypt` = '1'");
	$domain_ids = $sel_result->fetchAll(\PDO::FETCH_ASSOC);
	if (count($domain_ids) > 0) {
		$domain_in = "";
		foreach ($domain_ids as $domain_id) {
			$domain_in .= "'" . $domain_id['id'] . "',";
		}
		$domain_in = substr($domain_in, 0, - 1);
		Database::query("DELETE FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` WHERE `domainid` IN (" . $domain_in . ")");
	}
	// check for froxlor domain using let's encrypt
	if (Settings::Get('system.le_froxlor_enabled') == 1) {
		Database::query("DELETE FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` WHERE `domainid` = '0'");
	}
	lastStepStatus(0);

	showUpdateStep("Inserting job to regenerate configfiles");
	\Froxlor\System\Cronjob::inserttask('1');
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201902170');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201902170')) {

	showUpdateStep("Adding new froxlor vhost domain alias setting");
	Settings::AddNew('system.froxloraliases', "");
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201902210');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201902210')) {

	// set correct version for people that have tested 0.10.0 before
	\Froxlor\Froxlor::updateToVersion('0.10.0-rc1');
	\Froxlor\Froxlor::updateToDbVersion('201904100');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201904100')) {

	showUpdateStep("Converting all MyISAM tables to InnoDB");
	Database::needRoot(true);
	Database::needSqlData();
	$sql_data = Database::getSqlData();
	$result = Database::query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . $sql_data['db'] . "' AND ENGINE = 'MyISAM'");
	while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
		Database::query("ALTER TABLE `" . $row['TABLE_NAME'] . "` ENGINE=INNODB");
	}
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201904250');
}

if (\Froxlor\Froxlor::isFroxlorVersion('0.10.0-rc1')) {
	\Froxlor\Froxlor::updateToVersion('0.10.0-rc2');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201904250')) {

	showUpdateStep("Adding new settings for CAA");
	Settings::AddNew('caa.caa_entry', '', true);
	Settings::AddNew('system.dns_createcaaentry', 1, true);
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201907270');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201907270')) {

	showUpdateStep("Cleaning up old files");
	$to_clean = array(
		"actions/admin/settings/000.version.php",
		"actions/admin/settings/190.ticket.php",
		"admin_tickets.php",
		"customer_tickets.php",
		"install/scripts/language-check.php",
		"install/updates/froxlor/upgrade_syscp.inc.php",
		"lib/classes",
		"lib/configfiles/precise.xml",
		"lib/cron_init.php",
		"lib/cron_shutdown.php",
		"lib/formfields/admin/tickets",
		"lib/formfields/customer/tickets",
		"lib/functions.php",
		"lib/functions",
		"lib/navigation/10.tickets.php",
		"scripts/classes",
		"scripts/jobs",
		"templates/Sparkle/admin/tickets",
		"templates/Sparkle/customer/tickets"
	);
	foreach ($to_clean as $filedir) {
		$complete_filedir = \Froxlor\Froxlor::getInstallDir() . $filedir;
		if (file_exists($complete_filedir)) {
			Froxlor\FileDir::safe_exec("rm -rf " . escapeshellarg($complete_filedir));
		}
	}
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201909150');
}

if (\Froxlor\Froxlor::isFroxlorVersion('0.10.0-rc2')) {
	\Froxlor\Froxlor::updateToVersion('0.10.0');
}

if (\Froxlor\Froxlor::isDatabaseVersion('201909150')) {

	showUpdateStep("Adding TLSv1.3-cipherlist setting");
	Settings::AddNew("system.tlsv13_cipher_list", '');
	lastStepStatus(0);

	\Froxlor\Froxlor::updateToDbVersion('201910030');
}
