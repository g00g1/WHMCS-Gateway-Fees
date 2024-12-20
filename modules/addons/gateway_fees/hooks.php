<?php

// @ v2.5.2

use WHMCS\Session;
use WHMCS\User\Client;
use WHMCS\Billing\Invoice;
use WHMCS\Billing\Currency;
use WHMCS\Database\Capsule;
use WHMCS\Module\GatewaySetting;
use WHMCS\Service\Service as Service;
use WHMCS\Billing\Invoice\Item as InvoiceItem;
use WHMCS\Module\Addon\Setting as AddonSetting;

function gatewayFees($vars) {
	$invoiceId = $vars['invoiceid'];
	$paymentMethod = $vars['paymentmethod'];

	$invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
	if ($paymentMethod != $invoiceData['paymentmethod']) {
		$paymentMethod = $invoiceData['paymentmethod'];
	}

	InvoiceItem::where(['invoiceid' => $invoiceId, 'notes' => 'gateway_fees'])->delete();

	localAPI('UpdateInvoice', ['invoiceid' => $invoiceId]);

	$invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

	$taxable = false;
	$fixedFee = $percentageFee = $minFee = $maxFee = 0;

	$gatewayFees = AddonSetting::where('module', "gateway_fees")->get();

	foreach ($gatewayFees as $fee) {
		if ($fee->setting == "fixed_fee_{$paymentMethod}")
			$fixedFee = (float) $fee->value;

		if ($fee->setting == "percentage_fee_{$paymentMethod}")
			$percentageFee = (float) $fee->value;

		if ($fee->setting == "min_fee_{$paymentMethod}")
			$minFee = (float) $fee->value;

		if ($fee->setting == "max_fee_{$paymentMethod}")
			$maxFee = (float) $fee->value;

		if ($fee->setting == "enable_tax_{$paymentMethod}")
			$taxable = (bool) ($fee->value == "on");
	}

	$total = $invoiceData['subtotal'];
	$calcFee = round($fixedFee + $total * $percentageFee / 100, 2, PHP_ROUND_HALF_UP);

	if ($total > 0) {
		if ($maxFee != 0 && $maxFee < $calcFee) {
			$d = Currency::defaultCurrency()->first()->prefix . number_format($maxFee, 2);
			$amountDue = $maxFee;
		} elseif ($minFee != 0 && $minFee > $calcFee) {
			$d = Currency::defaultCurrency()->first()->prefix . number_format($minFee, 2);
			$amountDue = $minFee;
		} else {
			$amountDue = $calcFee;

			if ($fixedFee > 0 & $percentageFee > 0) {
				$d = Currency::defaultCurrency()->first()->prefix . number_format($fixedFee, 2) . " + {$percentageFee}%";
			} else if ($percentageFee > 0) {
				$d = "{$percentageFee}%";
			} else if ($fixedFee > 0) {
				$d = number_format($fixedFee, 2);
			}
		}

	}

	if ($d) {
		$feeDescription = GatewaySetting::where(['gateway' => $paymentMethod, 'setting' => 'name'])->first()->value . " Fees ({$d})";

		$id = InvoiceItem::insert([
			'userid' => Session::get('uid'),
			'invoiceid' => $invoiceId,
			'type' => 'Fee',
			'notes' => 'gateway_fees',
			'description' => $feeDescription,
			'amount' => $amountDue,
			'taxed'	 => $taxable ? '1' : '0',
			'duedate' => date('Y-m-d H:i:s'),
			'paymentmethod' => $paymentMethod,
		]);
	}

	localAPI('UpdateInvoice', ['invoiceid' => $invoiceId]);
}

function gatewayFeesRecalculate($vars) {
	$invoiceId = $vars['invoiceid'];

	$linesAll = InvoiceItem::where(['invoiceid' => $invoiceId])->count();
	$linesFee = InvoiceItem::where(['invoiceid' => $invoiceId, 'notes' => 'gateway_fees'])->count();

	if ($linesAll == $linesFee && $linesAll > 0) {
		InvoiceItem::where(['invoiceid' => $invoiceId, 'notes' => 'gateway_fees'])->delete();
		localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
	}
}

function gatewayFeesSplit($vars) {
	return gatewayFees(['invoiceid' => $vars['newinvoiceid']]);
}

add_hook("AddInvoiceLateFee", 1, "gatewayFees");
add_hook("AfterInvoicingGenerateInvoiceItems", 1, "gatewayFees");
add_hook("InvoiceChangeGateway", 1, "gatewayFees");
add_hook("InvoiceCreated", 1, "gatewayFees");
add_hook("InvoiceCreation", 1, "gatewayFees");
add_hook("InvoiceCreationAdminArea", 1, "gatewayFees");
add_hook("InvoiceSplit", 1, "gatewayFeesSplit");
add_hook("UpdateInvoiceTotal", PHP_INT_MAX, "gatewayFeesRecalculate");
add_hook("InvoiceCreationAdminArea", 1, "gatewayFees");
