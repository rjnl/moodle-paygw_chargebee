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
 * Privacy Subsystem implementation for paygw_stripe.
 *
 * @package    paygw_chargebee
 * @copyright  2022 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_chargebee\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\data_provider;
use core_privacy\local\request\writer;
use core_payment\privacy\paygw_provider;

/**
 * Privacy Subsystem implementation for paygw_chargebee.
 *
 * @copyright  2022 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements data_provider, paygw_provider, \core_privacy\local\metadata\provider {
    /**
     * Returns metadata about this plugin.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored in this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        // Data stored internally.
        $collection->add_database_table(
            'paygw_chargebee',
            [
                'userid' => 'privacy:metadata:paygw_chargebee:userid',
                'customerid' => 'privacy:metadata:paygw_chargebee:customerid',
                'transactionid' => 'privacy:metadata:paygw_chargebee:transactionid',
                'invoicenumber' => 'privacy:metadata:paygw_chargebee:invoicenumber',
                'amountpaid' => 'privacy:metadata:paygw_chargebee:amountpaid',
            ],
            'privacy:metadata:paygw_chargebee'
        );

        // Data shared with Chargebee.
        $collection->add_external_location_link(
            'chargebee_com',
            [
                'userid' => 'privacy:metadata:paygw_chargebee_com:userid',
                'firstname' => 'privacy:metadata:paygw_chargebee_com:firstname',
                'lastname' => 'privacy:metadata:paygw_chargebee_com:lastname',
                'email' => 'privacy:metadata:paygw_chargebee_com:email',
            ],
            'privacy:metadata:paygw_chargebee_com'
        );

        return $collection;
    }

    /**
     * Export all user data for the specified payment record, and the given context.
     *
     * @param \context $context Context
     * @param array $subcontext The location within the current context that the payment data belongs
     * @param \stdClass $payment The payment record
     */
    public static function export_payment_data(\context $context, array $subcontext, \stdClass $payment) {
        global $DB;

        $subcontext[] = get_string('gatewayname', 'paygw_chargebee');
        $record = $DB->get_record('paygw_chargebee', ['paymentid' => $payment->id]);

        $data = (object) [
            'transactionid' => $record->transactionid,
            'invoicenumber' => $record->invoicenumber,
        ];
        writer::with_context($context)->export_data(
            $subcontext,
            $data
        );
    }

    /**
     * Delete all user data related to the given payments.
     *
     * @param string $paymentsql SQL query that selects payment.id field for the payments
     * @param array $paymentparams Array of parameters for $paymentsql
     */
    public static function delete_data_for_payment_sql(string $paymentsql, array $paymentparams) {
        global $DB;

        // Instead of deleting the records, we will unset the userid and customerid,
        // since payment reports and audits may be based on this data.

        // Empty the userid and customerid fields.
        $DB->set_field_select('paygw_chargebee', 'userid', 0, "paymentid IN ({$paymentsql})", $paymentparams);
        $DB->set_field_select('paygw_chargebee', 'customerid', '', "paymentid IN ({$paymentsql})", $paymentparams);
    }
}
