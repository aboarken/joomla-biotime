<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;

/**
 * Administrator operations on #__biotime_usermap. Every change is audited with the administrator
 * as actor. One employee can never be mapped to two Joomla users.
 */
final class MappingManager
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param \Closure(): EmployeeDirectoryInterface $directory
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly UserMapRepositoryInterface $repository,
        private readonly \Closure $directory,
        private readonly AuditLogInterface $audit,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Map a Joomla user to the employee with this exact employee code.
     *
     * @return array<string, mixed> The employee record (to confirm the name to the administrator)
     *
     * @throws MappingException|SourceException
     */
    public function assign(int $joomlaUserId, string $joomlaEmail, string $empCode, int $actorId, ?string $expectedEmployeeId = null): array
    {
        if ($joomlaUserId <= 0) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_USER');
        }

        $empCode = trim($empCode);

        try {
            EmployeeKeys::code($empCode);
        } catch (\InvalidArgumentException) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_CODE');
        }

        $exact = array_values(array_filter(
            ($this->directory)()->employeesByCode($empCode),
            static fn (array $e): bool => (string) ($e['emp_code'] ?? '') === $empCode
        ));

        if ($exact === []) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_NOT_FOUND');
        }

        if (\count($exact) > 1) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_CODE_AMBIGUOUS');
        }

        $employee   = $exact[0];
        $employeeId = (string) ($employee['id'] ?? '');

        try {
            EmployeeKeys::id($employeeId);
        } catch (\InvalidArgumentException) {
            throw new SourceException(SourceException::BAD_RESPONSE, 'Employee without a valid id');
        }

        // Confirm (1.3.7): only the employee the match found; if the code now names someone else, refuse.
        if ($expectedEmployeeId !== null && $employeeId !== $expectedEmployeeId) {
            $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_DENIED, 'employee_changed', $actorId);

            throw new MappingException('COM_BIOTIME_MAPPING_ERR_CHANGED');
        }

        $owner = $this->repository->findByEmployeeId($employeeId);

        if ($owner !== null && $owner->joomlaUserId !== $joomlaUserId) {
            if ($owner->status === UserMapping::STATUS_ACTIVE) {
                $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_DENIED, 'employee_already_mapped:' . $owner->joomlaUserId, $actorId);

                throw new MappingException('COM_BIOTIME_MAPPING_ERR_TAKEN');
            }

            // 1.3.8: a match that was never confirmed (or was suspended) does not keep the employee
            // from the user the administrator names. Otherwise anyone could block a colleague's
            // mapping by putting the colleague's e-mail on their own account. The other user's row is
            // removed; their next visit is matched again.
            $this->repository->delete($owner->joomlaUserId);
            $this->audit->record($owner->joomlaUserId, 'mapping.release', AuditLogInterface::RESULT_OK, 'employee:' . $employeeId . ' for:' . $joomlaUserId, $actorId);
        }

        $now = ($this->clock)();

        try {
            $this->save($joomlaUserId, $employeeId, $empCode, $joomlaEmail, $now, $actorId);
        } catch (\RuntimeException) {
            // Another request mapped this employee between the check and the write (unique key).
            $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_DENIED, 'employee_already_mapped', $actorId);

            throw new MappingException('COM_BIOTIME_MAPPING_ERR_TAKEN');
        }

        $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_OK, 'employee:' . $employeeId, $actorId);

        return $employee;
    }

    private function save(int $joomlaUserId, string $employeeId, string $empCode, string $joomlaEmail, \DateTimeImmutable $now, int $actorId): void
    {
        $this->repository->save(new UserMapping(
            $joomlaUserId,
            $employeeId,
            $empCode,
            EmailNormalizer::normalize($joomlaEmail),
            UserMapping::STATUS_ACTIVE,
            UserMapping::SOURCE_ADMIN,
            $now,
            $now,
            $actorId
        ));
    }

    /**
     * Re-activate a mapping (e.g. after a suspension) for the same employee code.
     *
     * @return array<string, mixed>
     *
     * @throws MappingException|SourceException
     */
    public function confirm(int $joomlaUserId, string $joomlaEmail, int $actorId): array
    {
        $mapping = $this->repository->find($joomlaUserId);

        // 1.3.7 (audit): only a match that was found and is waiting (or was suspended) can be confirmed,
        // for the same employee. A "Not found" row may hold a code the user typed into their own
        // profile field (refused by matching because they can edit it): never confirm that.
        if ($mapping === null || $mapping->empCode === null || $mapping->employeeId === null
            || !\in_array($mapping->status, [UserMapping::STATUS_PENDING, UserMapping::STATUS_SUSPENDED], true)) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_NOTHING_TO_CONFIRM');
        }

        return $this->assign($joomlaUserId, $joomlaEmail, $mapping->empCode, $actorId, $mapping->employeeId);
    }

    /**
     * @throws MappingException
     */
    public function suspend(int $joomlaUserId, int $actorId): void
    {
        $mapping = $this->repository->find($joomlaUserId) ?? throw new MappingException('COM_BIOTIME_MAPPING_ERR_NO_MAPPING');

        $this->repository->save($mapping->with(['status' => UserMapping::STATUS_SUSPENDED, 'checkedAt' => ($this->clock)(), 'modifiedBy' => $actorId]));
        $this->audit->record($joomlaUserId, 'mapping.suspend', AuditLogInterface::RESULT_OK, '', $actorId);
    }

    /** Delete the mapping, so the next visit runs automatic matching again. */
    public function rematch(int $joomlaUserId, int $actorId): void
    {
        $had = $this->repository->find($joomlaUserId) !== null;
        $this->repository->delete($joomlaUserId);
        $this->audit->record($joomlaUserId, 'mapping.rematch', AuditLogInterface::RESULT_OK, $had ? '' : 'no_mapping', $actorId);
    }
}
