<?php

namespace WH1\PaygateWayForPay;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;
	
	public function installStep1(): void
	{
		$db = $this->db();

		$db->insert('xf_payment_provider', [
			'provider_id'    => "wh1WayForPay",
			'provider_class' => "WH1\\PaygateWayForPay:WayForPay",
			'addon_id'       => "WH1/PaygateWayForPay"
		]);
	}

	public function uninstallStep1()
	{
		$db = $this->db();

		$db->delete('xf_payment_profile', "provider_id = 'wh1WayForPay'");
		$db->delete('xf_payment_provider', "provider_id = 'wh1WayForPay'");
	}
}