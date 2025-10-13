<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Task to check and finalise an incomplete transaction.
 *
 * @package    paygw_chargebee
 * @copyright  2023 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace paygw_chargebee\task;

use ChargeBee\ChargeBee\Models\HostedPage;
use core_payment\helper as payment_helper;
use paygw_chargebee\chargebee_helper;

/**
 *
 * @copyright  2023 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalise_transaction extends \core\task\adhoc_task {
    /**
     * The finalise transaction task processing.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        // Get the data for this task.
        $data = $this->get_custom_data();

        // Cannot proceed if Chargebee identifier is not available.
        if (!isset($data->remotereference)) {
            return;
        }

        $config = (object) payment_helper::get_gateway_configuration(
            $data->component,
            $data->paymentarea,
            $data->itemid,
            'chargebee'
        );
        $chargebeehelper = new chargebee_helper($config->sitename, $config->apikey, $config->customeridprefix);

        // Get the hosted page details from Chargebee.
        $result = $chargebeehelper->get_hosted_page($data->remotereference);

        mtrace(" ->-> identifier: {$result->id}, state: {$result->state}");

        switch ($result->state) {
            case $chargebeehelper::STATE_ACKNOWLEDGED:
                // Payment record should already be updated locally, but double check it anyway.
                if (
                    $result->content['invoice']['status'] === 'paid' &&
                    $DB->record_exists(
                        'paygw_chargebee',
                        [
                            'invoicenumber' => $result->content['invoice']['id'],
                            'transactionid' => $result->content['invoice']['linked_payments'][0]['txn_id'],
                        ]
                    )
                ) {
                    // All good. Nothing to do.
                    mtrace(' + Invoice #: ' . $result->content['invoice']['id']);
                    mtrace('=== Nothing to do... ===');
                }
                break;
            case $chargebeehelper::STATE_SUCCEEDED:
                // Payment record should already be updated locally, but double check it anyway.
                if (
                    $DB->record_exists(
                        'paygw_chargebee',
                        [
                            'invoicenumber' => $result->content['invoice']['id'],
                            'transactionid' => $result->content['invoice']['linked_payments'][0]['txn_id'],
                        ]
                    )
                ) {
                    // Under normal circumstance, this should not happen.
                    // Send acknowledgement that we've processed this payment.
                    HostedPage::acknowledge($data->remotereference);
                    mtrace(' + Invoice #: ' . $result->content['invoice']['id']);
                    mtrace('*** Sending acknowledgement... ***');
                } else if ($result->content['invoice']['status'] === 'paid') {
                    mtrace('*** Updating local records ***');
                    // Save payment details in Moodle.
                    $payable = payment_helper::get_payable($data->component, $data->paymentarea, $data->itemid);
                    $surcharge = payment_helper::get_gateway_surcharge('chargebee');
                    $cost = payment_helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), $surcharge);

                    $paymentid = payment_helper::save_payment(
                        $payable->get_account_id(),
                        $data->component,
                        $data->paymentarea,
                        $data->itemid,
                        $data->userid,
                        $cost,
                        $payable->get_currency(),
                        'chargebee'
                    );

                    // Record Chargebee transaction details.
                    $invoicenumber = $chargebeehelper->save_transaction_details($data->remotereference, $data->userid, $paymentid);

                    payment_helper::deliver_order($data->component, $data->paymentarea, $data->itemid, $paymentid, $data->userid);

                    // Log events.
                    // Transaction successful.
                    $chargebeehelper->log_event(
                        CHARGEBEE_TRANSACTION_SUCCESSFUL,
                        [
                            'component' => $data->component,
                            'paymentarea' => $data->paymentarea,
                            'itemid' => $data->itemid,
                            'invoice' => $invoicenumber,
                            'paymentid' => $paymentid,
                        ]
                    );

                    // Transaction complete.
                    $chargebeehelper->log_event(
                        CHARGEBEE_TRANSACTION_COMPLETED,
                        [
                            'component' => $data->component,
                            'paymentarea' => $data->paymentarea,
                            'itemid' => $data->itemid,
                        ]
                    );

                    mtrace(' + Invoice #: ' . $invoicenumber);
                    mtrace('*** Order delivered by cli task. ***');
                } else {
                    // Invoice could also have been voided.
                    // Get the current status of this invoice.
                    $invoice = $chargebeehelper->get_invoice($result->content['invoice']['id']);

                    mtrace(' - Invoice #: ' . $invoice->id . ', status: ' . $invoice->status);

                    if ($invoice->status == 'voided') {
                        // Acknowledge this transaction.
                        HostedPage::acknowledge($data->remotereference);
                    } else if ($invoice->status == 'payment_due' && $config->autovoidinvoice == '1') {
                        // Void unpaid invoice.
                        $chargebeeresult = $chargebeehelper->void_unpaid_invoice($data->remotereference, $data->userid);

                        if ($chargebeeresult['status'] == 'voided') {
                            mtrace(' - Voiding Invoice #: ' . $invoice->id);
                            // Log event.
                            $chargebeehelper->log_event(
                                CHARGEBEE_VOID_INVOICE_SUCCESSFUL,
                                [
                                    'component' => $data->component,
                                    'paymentarea' => $data->paymentarea,
                                    'itemid' => $data->itemid,
                                    'invoice' => $chargebeeresult['invoice'],
                                ]
                            );
                            // Transaction complete.
                            $chargebeehelper->log_event(
                                CHARGEBEE_TRANSACTION_COMPLETED,
                                [
                                    'component' => $data->component,
                                    'paymentarea' => $data->paymentarea,
                                    'itemid' => $data->itemid,
                                ]
                            );
                        } else {
                            // Log event.
                            $chargebeehelper->log_event(
                                CHARGEBEE_VOID_INVOICE_FAILED,
                                [
                                    'component' => $data->component,
                                    'paymentarea' => $data->paymentarea,
                                    'itemid' => $data->itemid,
                                    'invoice' => $chargebeeresult['invoice'],
                                ]
                            );
                        }
                    }
                }
                break;
            case $chargebeehelper::STATE_REQUESTED:
                // Status is still "requested".
                // Let's try again one more time.
                if (!isset($data->retry)) {
                    $data->retry = 1;

                    // Create a new adhoc task to try again.
                    $retrytask = new \paygw_chargebee\task\finalise_transaction();
                    $retrytask->set_custom_data($data);
                    $retrytask->set_userid($data->userid);
                    $retrytask->set_next_run_time(time() + 600); // Run this task again after 10 minutes.
                    \core\task\manager::queue_adhoc_task($retrytask, true);

                    mtrace(' - Rescheduling to try again after 10 minutes.');
                } else {
                    mtrace(' - Re-trying after 10 minutes.');
                    // This transaction may have been abandoned. It is still not paid.
                    // Just drop it.
                    mtrace('=== Nothing to do... ===');
                }
                break;
        }
    }
}
