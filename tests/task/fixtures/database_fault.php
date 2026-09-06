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

namespace report_ai_analysis\task;

use moodle_database;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use ReflectionMethod;

/**
 * Forward public DML calls to the real backend, injecting exactly one selected persistence failure.
 *
 * Transactions and rollback use the actual database on every supported backend. The test must
 * disable its outer reset transaction and restore the original global database in finally.
 * No driver inheritance, deprecated PHPUnit proxying, fake permission answers or SQL dialect is used.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class database_fault {
    /** @var bool Whether the one-shot failure was reached. */
    public bool $triggered = false;

    /** @var array Actual update payloads, preserving PHP types before driver normalisation. */
    public array $updates = [];

    /** @var \moodle_transaction|null Last actual delegated transaction, for the disposed-handle failure case. */
    private ?\moodle_transaction $transaction = null;

    /**
     * Configure an abstract-DML PHPUnit double delegating all normal public operations.
     *
     * @param moodle_database $database Actual configured test database
     * @param moodle_database&MockObject $double PHPUnit mock of the abstract DML contract
     * @param int $reportid Report whose completion can fail
     * @param string $operation One of complete, disposed, queue, delete, or observe
     */
    public function __construct(
        moodle_database $database,
        moodle_database&MockObject $double,
        int $reportid,
        string $operation
    ) {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('This database fixture is for PHPUnit only');
        }
        foreach ((new ReflectionClass(moodle_database::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isFinal() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            $name = $method->getName();
            if ($name === 'dispose') {
                // The double's inherited destructor must not close a connection owned by the test runner.
                continue;
            }
            $double->method($name)->willReturnCallback(function (...$args) use ($database, $name, $reportid, $operation): mixed {
                if ($name === 'update_record' && $args[0] === 'report_ai_analysis_reports') {
                    $record = (object) $args[1];
                    $this->updates[] = clone $record;
                    if (
                        !$this->triggered && in_array($operation, ['complete', 'disposed'], true) &&
                            (int) $record->id === $reportid &&
                            ($record->status ?? '') === 'completed'
                    ) {
                        if (!$this->transaction || $this->transaction->is_disposed() || !$database->is_transaction_started()) {
                            throw new \coding_exception('Expected an active worker-owned completion transaction');
                        }
                        $this->triggered = true;
                        if ($operation === 'disposed') {
                            // Model a driver failing after core has disposed its delegated handle, but before SQL commit.
                            $this->transaction->dispose();
                        }
                        throw new \dml_write_exception('Injected completion write failure');
                    }
                }
                if (!$this->triggered && $operation === 'queue' && $name === 'insert_record' && $args[0] === 'task_adhoc') {
                    $this->triggered = true;
                    throw new \dml_write_exception('Injected retry queue failure');
                }
                if (
                    !$this->triggered && $operation === 'delete' && $name === 'delete_records' &&
                        $args[0] === 'report_ai_analysis_reports'
                ) {
                    $this->triggered = true;
                    throw new \dml_write_exception('Injected report deletion failure');
                }
                $result = $database->{$name}(...$args);
                if ($name === 'start_delegated_transaction') {
                    $this->transaction = $result;
                }
                return $result;
            });
        }
    }
}
