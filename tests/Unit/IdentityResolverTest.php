<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Database\DbEmployeeDirectory;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Identity\IdentityResolver;
use BiotimePortal\Component\Biotime\Administrator\Identity\MappingException;
use BiotimePortal\Component\Biotime\Administrator\Identity\MappingManager;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapping;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\CatalogueQuery;
use BiotimePortal\Component\Biotime\Tests\Support\FixedFieldReader;
use BiotimePortal\Component\Biotime\Tests\Support\MemoryAudit;
use BiotimePortal\Component\Biotime\Tests\Support\MemoryUserMapRepository;
use PHPUnit\Framework\TestCase;

/**
 * Employee matching: by the Joomla "employeeid" field first, then e-mail; single, ambiguous, none;
 * duplicates; suspension when the field or e-mail changes; daily re-verification. Employees come
 * from ZKBioTime's database (DbEmployeeDirectory over CatalogueQuery).
 */
final class IdentityResolverTest extends TestCase
{
    private CatalogueQuery $db;

    /** The database cannot be reached (DbException UNREACHABLE on every query). */
    private bool $down = false;

    private MemoryUserMapRepository $repo;

    private MemoryAudit $audit;

    private FixedFieldReader $fields;

    private \DateTimeImmutable $now;

    /** @var list<array<string, mixed>> */
    private array $employees = [
        ['id' => 11, 'emp_code' => 'E1', 'email' => 'Alice@Example.com', 'first_name' => 'Alice'],
        ['id' => 12, 'emp_code' => 'E10', 'email' => 'alice.b@example.com', 'first_name' => 'Alicia'],
        ['id' => 13, 'emp_code' => 'E2', 'email' => 'shared@example.com', 'first_name' => 'Bob'],
        ['id' => 14, 'emp_code' => 'E3', 'email' => 'shared@example.com', 'first_name' => 'Carl'],
        ['id' => 15, 'emp_code' => 'E4', 'email' => '', 'first_name' => 'Dina'],
    ];

    protected function setUp(): void
    {
        $this->repo   = new MemoryUserMapRepository();
        $this->audit  = new MemoryAudit();
        $this->fields = new FixedFieldReader();
        $this->now    = new \DateTimeImmutable('2026-11-15 10:00:00', new \DateTimeZone('UTC'));

        $rows = function (\Closure $filter): array {
            if ($this->down) {
                throw new DbException(DbException::UNREACHABLE, 'down');
            }

            return array_values(array_map(
                static fn (array $e): array => $e + ['last_name' => null],
                array_filter($this->employees, $filter)
            ));
        };

        // The code lookup is not trusted to be exact: here it also answers partial matches (E1
        // also finds E10), and the resolver must still compare emp_code exactly.
        $this->db = new CatalogueQuery('pgsql', [
            'WHERE emp_code = :code'  => static fn (array $p): array => $rows(static fn (array $e): bool => str_starts_with($e['emp_code'], $p[':code'])),
            'WHERE id = :id'          => static fn (array $p): array => $rows(static fn (array $e): bool => $e['id'] === $p[':id']),
            'FROM personnel_employee' => static fn (): array => $rows(static fn (array $e): bool => $e['email'] !== ''),
        ]);
    }

    private function directory(): DbEmployeeDirectory
    {
        return new DbEmployeeDirectory(fn () => $this->db);
    }

    /** Queries sent so far, all or those containing $fragment. */
    private function queries(string $fragment = ''): int
    {
        return \count(array_filter($this->db->sent, static fn (string $sql): bool => str_contains($sql, $fragment)));
    }

    private function resolver(bool $autoMatch = true, bool $autoApprove = false): IdentityResolver
    {
        return new IdentityResolver($this->repo, fn () => $this->directory(), $this->audit, new ArrayLogger(), $autoMatch, fn () => $this->now, $this->fields, $autoApprove);
    }

    public function testFieldCodeMapsExactlyOneEmployeeEvenWhenTheFilterReturnsPartialMatches(): void
    {
        $this->fields->values[5] = 'E1';

        $identity = $this->resolver()->resolve(5, 'someone@else.com');

        self::assertTrue($identity->isMatched());
        self::assertSame('11', $identity->employeeId());
        self::assertSame('E1', $identity->empCode());
        self::assertSame(1, $this->queries('WHERE emp_code = :code'));
        self::assertStringContainsString('by_field', $this->audit->records[0]['detail']);
    }

    public function testUnknownFieldCodeIsNotFoundAndNeverFallsBackToEmail(): void
    {
        $this->fields->values[5] = 'E99';

        $identity = $this->resolver()->resolve(5, 'alice@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $identity->status);
        self::assertSame('E99', $this->repo->find(5)?->empCode, 'the tried code is kept, so a corrected field is retried at once');
    }

    public function testEmailMatchIsExactAndCaseInsensitive(): void
    {
        $identity = $this->resolver()->resolve(5, ' ALICE@example.COM ');

        // Found, and waiting for an administrator (1.0.10): Joomla e-mails are not verified.
        self::assertFalse($identity->isMatched());
        self::assertSame(['11', 'E1', UserMapping::STATUS_PENDING, UserMapping::SOURCE_AUTO], [$this->repo->find(5)?->employeeId, $this->repo->find(5)?->empCode, $this->repo->find(5)?->status, $this->repo->find(5)?->source]);
    }

    public function testSharedEmailIsAmbiguous(): void
    {
        $identity = $this->resolver()->resolve(5, 'shared@example.com');

        self::assertSame(UserMapping::STATUS_AMBIGUOUS, $identity->status);
        self::assertFalse($identity->isMatched());
    }

    public function testNoMatchIsNotFound(): void
    {
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $this->resolver()->resolve(5, 'nobody@example.com')->status);
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $this->resolver()->resolve(6, 'not-an-email')->status);
    }

    public function testEmailMatchingCanBeTurnedOff(): void
    {
        $identity = $this->resolver(false)->resolve(5, 'alice@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(0, $this->queries(), 'ZKBioTime is not asked');
    }

    public function testOneEmployeeIsNeverMappedToTwoUsers(): void
    {
        $this->fields->values[5] = 'E1';
        $this->fields->values[6] = 'E1';

        self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched());
        $second = $this->resolver()->resolve(6, 'b@x.com');

        self::assertSame(UserMapping::STATUS_AMBIGUOUS, $second->status);
        self::assertSame(5, $this->repo->findByEmployeeId('11')?->joomlaUserId);
    }

    public function testActiveMappingNeedsNoLookup(): void
    {
        $this->fields->values[5] = 'E1';
        $this->resolver()->resolve(5, 'a@x.com');
        $calls = $this->queries();

        $identity = $this->resolver()->resolve(5, 'a@x.com');

        self::assertTrue($identity->isMatched());
        self::assertSame($calls, $this->queries());
    }

    public function testChangedFieldSuspendsTheMapping(): void
    {
        $this->fields->values[5] = 'E1';
        $this->resolver()->resolve(5, 'a@x.com');

        $this->fields->values[5] = 'E2';
        $identity = $this->resolver()->resolve(5, 'a@x.com');

        self::assertSame(UserMapping::STATUS_SUSPENDED, $identity->status);
        self::assertContains('identity.suspended', $this->audit->actions());
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->resolver()->resolve(5, 'a@x.com')->status, 'stays suspended');
    }

    public function testChangedEmailSuspendsAnEmailMappingButNotAFieldMapping(): void
    {
        $this->resolver()->resolve(5, 'alice@example.com');
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->resolver()->resolve(5, 'new@example.com')->status);

        $this->fields->values[6] = 'E2';
        $this->resolver()->resolve(6, 'old@example.com');
        self::assertTrue($this->resolver()->resolve(6, 'new@example.com')->isMatched());
    }

    public function testNotFoundIsRetriedOnlyAfterTheRecheckTimeUnlessTheFieldChanged(): void
    {
        $this->fields->values[5] = 'E99';
        $this->resolver()->resolve(5, 'a@x.com');
        $calls = $this->queries();

        $this->resolver()->resolve(5, 'a@x.com');
        self::assertSame($calls, $this->queries(), 'reused within the recheck time');

        $this->fields->values[5] = 'E1';
        self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched(), 'a corrected field is used at once');
    }

    public function testDailyReverificationSuspendsWhenTheEmployeeCodeChanged(): void
    {
        $this->fields->values[5] = 'E1';
        $this->resolver()->resolve(5, 'a@x.com');

        $this->employees[0]['emp_code'] = 'E1-OLD';
        $this->now = $this->now->modify('+25 hours');

        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->resolver()->resolve(5, 'a@x.com')->status);
    }

    public function testAnEmployeeCodeFieldTheUserCanEditIsNotTrusted(): void
    {
        $this->fields->values[5]   = 'E1';
        $this->fields->editableBy  = [5];

        $identity = $this->resolver()->resolve(5, 'someone@x.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $identity->status);
        self::assertSame(0, $this->queries(), 'ZKBioTime is not even asked');
    }

    /** 1.0.10: every match by e-mail waits for an administrator, whether or not the e-mail changed. */
    public function testEveryMatchByEmailWaitsForAnAdministrator(): void
    {
        // First visit: no employee has this e-mail.
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $this->resolver()->resolve(7, 'nobody@x.com')->status);

        // The user changes their Joomla e-mail to a colleague's (Joomla does not verify it).
        $this->now = $this->now->modify('+1 minute');
        $identity  = $this->resolver()->resolve(7, 'alice@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(UserMapping::STATUS_PENDING, $identity->status);
        self::assertSame(UserMapping::STATUS_PENDING, $this->repo->find(7)?->status);
        self::assertContains('identity.pending', $this->audit->actions());

        // Also a user who had that e-mail from the start: Joomla cannot tell the two apart.
        self::assertFalse($this->resolver()->resolve(8, 'alice.b@example.com')->isMatched());
        self::assertSame(UserMapping::STATUS_PENDING, $this->repo->find(8)?->status);

        // Waiting: later visits read nothing from ZKBioTime and stay waiting.
        $sent = $this->queries();
        self::assertSame(UserMapping::STATUS_PENDING, $this->resolver()->resolve(8, 'alice.b@example.com')->status);
        self::assertSame($sent, $this->queries());
    }

    /** 1.0.19: "New users: Approve automatically" maps a match by e-mail at once. */
    public function testAutomaticApprovalMapsAMatchByEmailAtOnce(): void
    {
        $identity = $this->resolver(true, true)->resolve(5, 'alice@example.com');

        self::assertTrue($identity->isMatched());
        self::assertSame(['11', 'E1'], [$identity->employeeId(), $identity->empCode()]);
        self::assertSame(UserMapping::STATUS_ACTIVE, $this->repo->find(5)?->status);
        self::assertContains('identity.matched', $this->audit->actions());

        // A changed e-mail still suspends it, as before.
        $this->now = $this->now->modify('+1 minute');
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->resolver(true, true)->resolve(5, 'other@example.com')->status);
    }

    /** 1.0.19: a waiting user is approved on the next visit once automatic approval is on, after a fresh check. */
    public function testWaitingUsersAreApprovedOnTheirNextVisitOnceAutomaticApprovalIsOn(): void
    {
        self::assertSame(UserMapping::STATUS_PENDING, $this->resolver()->resolve(5, 'alice@example.com')->status);

        $this->now = $this->now->modify('+1 day');
        $identity  = $this->resolver(true, true)->resolve(5, 'alice@example.com');

        self::assertTrue($identity->isMatched());
        self::assertSame(UserMapping::STATUS_ACTIVE, $this->repo->find(5)?->status);

        // The address no longer belongs to exactly one employee: not approved.
        self::assertSame(UserMapping::STATUS_PENDING, $this->resolver()->resolve(6, 'alice.b@example.com')->status);
        $this->employees[] = ['id' => 16, 'emp_code' => 'E6', 'email' => 'alice.b@example.com', 'first_name' => 'Twin'];
        self::assertSame(UserMapping::STATUS_AMBIGUOUS, $this->resolver(true, true)->resolve(6, 'alice.b@example.com')->status);
    }

    /** 1.0.19: a waiting user whose Joomla e-mail changes is suspended, never matched by the new address. */
    public function testAWaitingUserWhoseEmailChangesIsSuspended(): void
    {
        self::assertSame(UserMapping::STATUS_PENDING, $this->resolver()->resolve(5, 'alice@example.com')->status);
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->resolver(true, true)->resolve(5, 'alice.b@example.com')->status);
        self::assertSame('11', $this->repo->find(5)?->employeeId, 'still the employee found first');
    }

    /** 1.0.19: an administrator confirms a waiting user in User Mappings. */
    public function testAnAdministratorConfirmsAWaitingUser(): void
    {
        $this->resolver()->resolve(5, 'alice@example.com');
        (new MappingManager($this->repo, fn () => $this->directory(), $this->audit, fn () => $this->now))->confirm(5, 'alice@example.com', 99);

        self::assertTrue($this->repo->find(5)?->isActive());
        self::assertSame(UserMapping::SOURCE_ADMIN, $this->repo->find(5)?->source);
        self::assertTrue($this->resolver()->resolve(5, 'alice@example.com')->isMatched());
    }

    /**
     * 1.3.7 (audit): a code the user typed into a field they can edit is refused by matching and
     * kept on a "Not found" row; Confirm must never turn it into a mapping.
     */
    public function testAUserTypedCodeOnANotFoundRowCannotBeConfirmed(): void
    {
        $this->fields->values[5] = 'E1';
        $this->fields->editableBy[] = 5;
        self::assertFalse($this->resolver()->resolve(5, 'mallory@example.com')->isMatched());
        self::assertSame([UserMapping::STATUS_NOT_FOUND, 'E1', null], [$this->repo->find(5)?->status, $this->repo->find(5)?->empCode, $this->repo->find(5)?->employeeId]);

        try {
            (new MappingManager($this->repo, fn () => $this->directory(), $this->audit, fn () => $this->now))->confirm(5, 'mallory@example.com', 99);
            self::fail('A typed code was confirmed');
        } catch (MappingException $e) {
            self::assertSame('COM_BIOTIME_MAPPING_ERR_NOTHING_TO_CONFIRM', $e->getMessage());
        }

        self::assertFalse($this->repo->find(5)?->isActive());
    }

    /** 1.3.7 (audit): a waiting match is confirmed only for the employee it found. */
    public function testConfirmRefusesWhenTheCodeNowBelongsToAnotherEmployee(): void
    {
        $this->resolver()->resolve(5, 'alice@example.com');
        $waiting = $this->repo->find(5);
        self::assertSame(UserMapping::STATUS_PENDING, $waiting?->status);
        $this->repo->save($waiting->with(['employeeId' => '999']));

        try {
            (new MappingManager($this->repo, fn () => $this->directory(), $this->audit, fn () => $this->now))->confirm(5, 'alice@example.com', 99);
            self::fail('Confirmed for another employee');
        } catch (MappingException $e) {
            self::assertSame('COM_BIOTIME_MAPPING_ERR_CHANGED', $e->getMessage());
        }
    }

    /** A failed daily re-verification is not retried on every page load (each try waits for ZKBioTime's database). */
    public function testFailedReverificationIsRetriedAfterAnHourNotOnEveryPage(): void
    {
        $this->fields->values[5] = 'E1';
        $this->resolver()->resolve(5, 'a@x.com');

        $this->down = true;
        $this->now  = $this->now->modify('+25 hours');
        $tries      = fn (): int => $this->queries('WHERE id = :id');

        self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched(), 'the verified mapping is kept');
        $after = $tries();
        self::assertGreaterThan(0, $after);

        $failedAt = $this->now;

        foreach ([1, 30, 59] as $minutes) {
            $this->now = $failedAt->modify("+{$minutes} minutes");
            self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched());
        }

        self::assertSame($after, $tries(), 'no new attempt within the hour');

        $this->down = false;
        $this->now  = $failedAt->modify('+61 minutes');
        self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched());
        self::assertGreaterThan($after, $tries(), 'tried again after an hour');

        $before    = $tries();
        $this->now = $this->now->modify('+2 hours');
        $this->resolver()->resolve(5, 'a@x.com');
        self::assertSame($before, $tries(), 'verified again: next check in a day');
    }

    public function testAdministratorMapsByExactCodeAndDuplicatesAreRefused(): void
    {
        $manager = new MappingManager($this->repo, fn () => $this->directory(), $this->audit, fn () => $this->now);

        $employee = $manager->assign(5, 'a@x.com', 'E1', 99);
        self::assertSame('Alice', $employee['first_name']);
        self::assertSame(UserMapping::SOURCE_ADMIN, $this->repo->find(5)?->source);
        self::assertSame(99, $this->audit->records[0]['actor']);

        foreach ([[6, 'E1', 'COM_BIOTIME_MAPPING_ERR_TAKEN'], [6, 'E9', 'COM_BIOTIME_MAPPING_ERR_NOT_FOUND'], [6, 'E 1', 'COM_BIOTIME_MAPPING_ERR_CODE'], [0, 'E2', 'COM_BIOTIME_MAPPING_ERR_USER']] as [$user, $code, $key]) {
            try {
                $manager->assign($user, 'b@x.com', $code, 99);
                self::fail('Expected ' . $key);
            } catch (MappingException $e) {
                self::assertSame($key, $e->getMessage());
            }
        }

        $manager->suspend(5, 99);
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->repo->find(5)?->status);
        $manager->confirm(5, 'a@x.com', 99);
        self::assertTrue($this->repo->find(5)?->isActive());
        $manager->rematch(5, 99);
        self::assertNull($this->repo->find(5));
        self::assertSame(['mapping.assign', 'mapping.assign', 'mapping.suspend', 'mapping.assign', 'mapping.rematch'], array_values(array_filter($this->audit->actions(), static fn ($a) => str_starts_with($a, 'mapping.'))));
    }

    /**
     * 1.3.8 (audit): a match that still waits for confirmation (anyone can create one by putting a
     * colleague's e-mail on their own account) does not keep the administrator from mapping the
     * employee to the right user. An active mapping still does.
     */
    public function testAWaitingMatchDoesNotBlockTheAdministratorsMapping(): void
    {
        $this->resolver()->resolve(5, 'alice@example.com');
        self::assertSame(UserMapping::STATUS_PENDING, $this->repo->find(5)?->status);

        $manager = new MappingManager($this->repo, fn () => $this->directory(), $this->audit, fn () => $this->now);
        $manager->assign(6, 'real-alice@example.com', 'E1', 99);

        self::assertTrue($this->repo->find(6)?->isActive());
        self::assertNull($this->repo->find(5), 'the waiting row is removed; that user is matched again on the next visit');
        self::assertContains('mapping.release', $this->audit->actions());

        try {
            $manager->assign(7, 'c@x.com', 'E1', 99);
            self::fail('an active mapping keeps its employee');
        } catch (MappingException $e) {
            self::assertSame('COM_BIOTIME_MAPPING_ERR_TAKEN', $e->getMessage());
        }
    }

    public function testAdministratorMappingIsNotSuspendedByEmailOrField(): void
    {
        (new MappingManager($this->repo, fn () => $this->directory(), $this->audit, fn () => $this->now))->assign(5, 'a@x.com', 'E4', 1);
        $this->fields->values[5] = 'E1';

        self::assertSame('E4', $this->resolver()->resolve(5, 'other@x.com')->empCode());
    }
}
