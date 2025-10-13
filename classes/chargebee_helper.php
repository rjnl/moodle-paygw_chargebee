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
 * Various helper methods for interacting with the Chargebee API
 *
 * @package    paygw_chargebee
 * @copyright  2022 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_chargebee;

use ChargeBee\ChargeBee\Environment;
use ChargeBee\ChargeBee\Models\HostedPage;
use ChargeBee\ChargeBee\Models\Invoice;
use context_course;
use context_block;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/payment/gateway/chargebee/.extlib/autoload.php');

// Event names.
define('CHARGEBEE_TRANSACTION_STARTED', 'transaction_started');
define('CHARGEBEE_TRANSACTION_SUCCESSFUL', 'transaction_successful');
define('CHARGEBEE_TRANSACTION_FAILED', 'transaction_failed');
define('CHARGEBEE_TRANSACTION_COMPLETED', 'transaction_completed');
define('CHARGEBEE_TRANSACTION_CANCELLED', 'transaction_cancelled');
define('CHARGEBEE_VOID_INVOICE_SUCCESSFUL', 'void_invoice_successful');
define('CHARGEBEE_VOID_INVOICE_FAILED', 'void_invoice_failed');

/**
 * The helper class for Chargebee payment gateway.
 *
 * @copyright  2022 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chargebee_helper {
    /**
     * @var string Transaction status - Success
     */
    public const STATUS_SUCCEEDED = 'succeeded';

    /**
     * @var string Transaction status - Failed
     */
    public const STATUS_FAILED = 'failed';

    /**
     * @var string Transaction status - Cancelled
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var string Hosted page state - Requested
     */
    public const STATE_REQUESTED = 'requested';

    /**
     * @var string Hosted page state - Succeeded
     */
    public const STATE_SUCCEEDED = 'succeeded';

    /**
     * @var string Hosted page state - Acknowledged
     */
    public const STATE_ACKNOWLEDGED = 'acknowledged';

    /**
     * @var string Hosted page state - Cancelled
     */
    public const STATE_CANCELLED = 'cancelled';

    /**
     * @var  string Chargebee Site name.
     */
    private $sitename;
    /**
     * @var string Chargebee API key.
     */
    private $apikey;

    /**
     * @var string Prefix to use when creating customers in Chargebee.
     */
    private $customeridprefix;

    /**
     * Initialise the Chargebee API client.
     *
     * @param string $sitename
     * @param string $apikey
     * @param string $customeridprefix
     */
    public function __construct(string $sitename, string $apikey, string $customeridprefix) {
        $this->apikey = $apikey;
        $this->sitename = $sitename;
        $this->customeridprefix = $customeridprefix;

        Environment::configure($this->sitename, $this->apikey);
    }

    /**
     * Fetch Hosted page for one-time payment checkout and return url.
     *
     * @param \stdClass $user
     * @param float $cost
     * @param string $currency
     * @param string $description
     * @param string $redirecturl
     * @return mixed An object containing the ID and URL of the Chargebee HostedPage.
     */
    public function get_checkout_url($user, float $cost, string $currency, string $description, string $redirecturl) {
        // Get the Chargebee HostedPage.
        $result = HostedPage::checkoutOneTime([
            "currency_code" => $currency,
            "redirectUrl" => $redirecturl,
            "customer" => [
                "id" => $this->customeridprefix . $user->id,
                "email" => $user->email,
                "firstName" => $user->firstname,
                "lastName" => $user->lastname,
            ],
            "charges" => [
                [
                    "amount" => $this->get_unit_amount($cost, $currency),
                    "description" => $description,
                ],
            ],
        ]);

        // Return the id and url of the HostedPage.
        $chargebeeurl = new \stdClass();
        $chargebeeurl->id = $result->hostedPage()->id;
        $chargebeeurl->url = $result->hostedPage()->url;

        return $chargebeeurl;
    }

    /**
     * Verify this transaction is authentic, and corresponds to current transaction.
     *
     * @param string $identifier unique identifier of the hosted page resource
     * @param  int $userid id of the user
     * @return bool
     */
    public function verify_transaction(string $identifier, int $userid): bool {
        global $DB;

        // Retrieve hosted page.
        $hostedpage = $this->get_hosted_page($identifier);

        if (
            $hostedpage->content['invoice']['status'] === 'paid' &&
            $hostedpage->content['invoice']['customer_id'] == $this->customeridprefix . $userid
        ) {
            // Check if invoice transaction id exists in db already.
            $record = $DB->get_record(
                'paygw_chargebee',
                [
                    'transactionid' => $hostedpage->content['invoice']['linked_payments'][0]['txn_id'],
                    'userid' => $userid,
                ]
            );

            if (!$record) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a Chargebee Hosted Page from a given unique identifier
     *
     * @param string $identifier unique identifier of the hosted page resource
     * @return mixed The Chargebee hosted page
     */
    public function get_hosted_page(string $identifier) {
        // Retrieve hosted page.
        $result = HostedPage::retrieve($identifier);

        return $result->hostedPage();
    }

    /**
     * Retrieve a Chargebee invoice
     *
     * @param string $invoiceid Invoice id/number
     * @return mixed Resource object representing a Chargebee invoice
     */
    public function get_invoice(string $invoiceid) {
        // Retrieve invoice.
        $result = Invoice::retrieve($invoiceid);

        return $result->invoice();
    }

    /**
     * Void an unpaid invoice
     *
     * @param string $identifier unique identifier of the hosted page resource
     * @param  int $userid id of the user
     * @return mixed Array with invoice id and invoice status [voided, payment_due, paid]
     */
    public function void_unpaid_invoice(string $identifier, int $userid): array {
        // Retrieve hosted page.
        $hostedpage = $this->get_hosted_page($identifier);

        try {
            if (
                $hostedpage->content['invoice']['status'] === 'payment_due' &&
                $hostedpage->content['invoice']['customer_id'] == $this->customeridprefix . $userid &&
                $hostedpage->content['invoice']['amount_paid'] == '0'
            ) {
                // We have an unpaid invoice, and nothing has been paid yet.
                $result = Invoice::voidInvoice(
                    $hostedpage->content['invoice']['id'],
                    ['comment' => get_string('commentvoidinvoice', 'paygw_chargebee')]
                );

                $invoice = $result->invoice();

                // Send acknowledgement that we've processed this payment.
                $ack = HostedPage::acknowledge($identifier);

                return ['invoice' => $invoice->id, 'status' => $invoice->status];
            }
        } catch (\Exception $e) {
            return ['invoice' => '', 'status' => '']; // Just return empty values.
        }
    }

    /**
     * Record Chargebee transaction details
     *
     * @param string $identifier unique identifier of the hosted page resource
     * @param integer $userid id of the user
     * @param integer $paymentid id from payments table
     * @return string return the invoice number
     */
    public function save_transaction_details(string $identifier, int $userid, int $paymentid): string {
        global $DB;

        $hostedpage = $this->get_hosted_page($identifier);

        $record = new \stdClass();
        $record->paymentid = $paymentid;
        $record->userid = $userid;
        $record->customerid = $hostedpage->content['invoice']['customer_id'];
        $record->transactionid = $hostedpage->content['invoice']['linked_payments'][0]['txn_id'];
        $record->invoicenumber = $hostedpage->content['invoice']['id'];
        $record->amountpaid = $this->get_paid_amount(
            $hostedpage->content['invoice']['amount_paid'],
            $hostedpage->content['invoice']['currency_code']
        );

        $DB->insert_record('paygw_chargebee', $record);

        // Send acknowledgement that we've processed this payment.
        $result = HostedPage::acknowledge($identifier);

        return $record->invoicenumber;
    }

    /**
     * Convert the cost into the unit amount accounting for zero-decimal currencies.
     *
     * @param float $cost
     * @param string $currency
     * @return float
     */
    public function get_unit_amount(float $cost, string $currency): float {
        if (in_array($currency, gateway::get_zero_decimal_currencies())) {
            return $cost;
        }
        return $cost * 100;
    }

    /**
     * Convert the amount paid into the decimal amount accounting for zero-decimal currencies.
     *
     * @param float $amount
     * @param string $currency
     * @return float
     */
    public function get_paid_amount(float $amount, string $currency): float {
        if (in_array($currency, gateway::get_zero_decimal_currencies())) {
            return $amount;
        }
        return $amount / 100;
    }

    /**
     * Build an array of event data
     *
     * @param array $data
     * @return array The event data array
     */
    public function build_event_data($data) {
        global $DB, $USER;

        if ($data['component'] == 'enrol_fee' && $data['paymentarea'] == 'fee') { // Course enrollment.
            $courseid = $DB->get_field('enrol', 'courseid', ['enrol' => 'fee', 'id' => $data['itemid']]);
            $context = context_course::instance($courseid);
        } else if (substr($data['component'], 0, 6) == 'block_') { // Block-level payment.
            $context = context_block::instance($data['itemid']);
        } else {
            $context = context_module::instance($data['itemid']); // Activity-level payment.
        }

        $other = [
            'itemid' => $data['itemid'],
            'component' => $data['component'],
        ];

        if (isset($data['paymentid'])) {
            $other['paymentid'] = $data['paymentid'];
        }

        if (isset($data['invoice'])) {
            $other['invoice'] = $data['invoice'];
        }

        if (isset($data['failurereason'])) {
            $other['failurereason'] = $data['failurereason'];
        }

        $eventdata = [
            'context' => $context,
            'relateduserid' => $USER->id,
            'other' => $other,
        ];

        return $eventdata;
    }

    /**
     * Log the event
     *
     * @param string $eventtype Type of event
     * @param array $data Data for the event
     * @return void
     */
    public function log_event($eventtype, $data) {
        $eventdata = $this->build_event_data($data);

        // TODO: This can be simplified??
        switch ($eventtype) {
            case CHARGEBEE_TRANSACTION_STARTED:
                $event = \paygw_chargebee\event\transaction_started::create($eventdata);
                break;
            case CHARGEBEE_TRANSACTION_COMPLETED:
                $event = \paygw_chargebee\event\transaction_completed::create($eventdata);
                break;
            case CHARGEBEE_TRANSACTION_SUCCESSFUL:
                $event = \paygw_chargebee\event\transaction_successful::create($eventdata);
                break;
            case CHARGEBEE_TRANSACTION_FAILED:
                $event = \paygw_chargebee\event\transaction_failed::create($eventdata);
                break;
            case CHARGEBEE_VOID_INVOICE_SUCCESSFUL:
                $event = \paygw_chargebee\event\void_invoice_successful::create($eventdata);
                break;
            case CHARGEBEE_VOID_INVOICE_FAILED:
                $event = \paygw_chargebee\event\void_invoice_failed::create($eventdata);
                break;
            case CHARGEBEE_TRANSACTION_CANCELLED:
                $event = \paygw_chargebee\event\transaction_cancelled::create($eventdata);
                break;
            default:
                return;
            break;
        }

        $event->trigger();
    }
}
