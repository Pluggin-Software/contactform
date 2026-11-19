<?php
/**
 * database updates for this version
 *
 * $updates = [
 *     "-- version number (1.2.2) --" => [
 *          "table_name" => "-- SQL Statement --",
 *      ],
 * ];
 */

if (!function_exists('contactform_add_spam_reason')) {

    /**
     * Give cf_submissions its spam_reason column if it has none.
     *
     * Asked of information_schema rather than written as ADD COLUMN IF NOT
     * EXISTS, which is MariaDB's alone: MySQL refuses it as a syntax error
     * (kevinbruce.com, 2026-10-23). A fresh install already has the column
     * from its install SQL, so there this does nothing; it is safe to run
     * twice.
     *
     * @param \estuaries\Modules $module the module running the update
     */
    function contactform_add_spam_reason($module): string
    {
        if ($module->db->column_exists('cf_submissions', 'spam_reason')) {
            return "&nbsp;&nbsp;- cf_submissions already has spam_reason<br>";
        }

        $module->db->update("ALTER TABLE cf_submissions
            ADD COLUMN spam_reason VARCHAR(255) NULL
                COMMENT 'why it was taken for spam, in words staff can judge'
            AFTER status");

        return "&nbsp;&nbsp;- spam_reason added to cf_submissions<br>";
    }
}

$updates = [
    /*
     * 1.0.1: a message taken for spam is kept with the reason, not dropped, so
     * staff can judge it and rescue a person a check misjudged.
     */
    '1.0.1' => [
        '__run_update_function__' => 'contactform_add_spam_reason',
    ],
    /*
     * 1.0.3: the same step again. 1.0.1 first shipped as ADD COLUMN IF NOT
     * EXISTS, which MySQL refuses, and a failed statement is still recorded as
     * run, so a MySQL site that met it has 1.0.1 marked done whether or not
     * the column exists.
     */
    '1.0.3' => [
        '__run_update_function__' => 'contactform_add_spam_reason',
    ],
];
