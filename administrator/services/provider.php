<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Audit\DbAuditLog;
use BiotimePortal\Component\Biotime\Administrator\Cache\DbUserCache;
use BiotimePortal\Component\Biotime\Administrator\Cache\UserCacheInterface;
use BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader;
use BiotimePortal\Component\Biotime\Administrator\Database\LeaveReader;
use BiotimePortal\Component\Biotime\Administrator\Database\TeamReader;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbEmployeeDirectory;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Database\Query;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Identity\DbUserFieldReader;
use BiotimePortal\Component\Biotime\Administrator\Identity\DbUserMapRepository;
use BiotimePortal\Component\Biotime\Administrator\Identity\IdentityResolver;
use BiotimePortal\Component\Biotime\Administrator\Identity\MappingManager;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapRepositoryInterface;
use BiotimePortal\Component\Biotime\Administrator\Log\JoomlaLogger;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;
use BiotimePortal\Component\Biotime\Administrator\Security\RateLimiter;
use BiotimePortal\Component\Biotime\Administrator\Security\SecretProvider;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsRepository;

return new class () implements ServiceProviderInterface {


    public function register(Container $container): void
    {
        $namespace = '\\BiotimePortal\\Component\\Biotime';

        $container->registerServiceProvider(new MVCFactory($namespace));
        $container->registerServiceProvider(new ComponentDispatcherFactory($namespace));
        $container->registerServiceProvider(new RouterFactory($namespace));

        $container->share(
            SettingsRepository::class,
            static fn (Container $c): SettingsRepository => new SettingsRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            JoomlaLogger::class,
            static fn (Container $c): JoomlaLogger => new JoomlaLogger($c->get(SettingsRepository::class)->load()->detailedLog())
        );

        $container->share(
            SecretProvider::class,
            static fn (): SecretProvider => new SecretProvider((string) Factory::getApplication()->get('secret', ''))
        );

        // Encrypts the database password at rest; throws KeyVaultException without a usable secret.
        $container->share(
            KeyVault::class,
            static fn (Container $c): KeyVault => new KeyVault($c->get(SecretProvider::class)->getSecret())
        );

        $container->share(
            DbAuditLog::class,
            static fn (Container $c): DbAuditLog => new DbAuditLog($c->get(DatabaseInterface::class), $c->get(JoomlaLogger::class))
        );
        $container->alias(AuditLogInterface::class, DbAuditLog::class);

        $container->share(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter($c->get(DbAuditLog::class)));

        // One read-only connection to ZKBioTime's database per page load, opened when first needed
        // (attendance and employee lookups share it). The pages need PostgreSQL.
        $container->share(
            Query::class,
            static function (Container $c): Query {
                $settings = $c->get(SettingsRepository::class)->load();

                if (!$settings->readsDatabase()) {
                    throw new DbException(DbException::NOT_CONFIGURED, 'The PostgreSQL connection to ZKBioTime\'s database is not set up');
                }

                try {
                    $vault = $c->get(KeyVault::class);
                } catch (KeyVaultException $e) {
                    throw new DbException(DbException::NOT_CONFIGURED, 'No encryption secret for the database password', '', $e);
                }

                // Kept open between pages (1.0.10): no new encrypted connection per page.
                return (new DbConnector())->connect($settings->dbConfig($vault), true);
            }
        );

        // Employees for matching Joomla users, from the database (0.6.1).
        $container->share(
            DbEmployeeDirectory::class,
            static fn (Container $c): DbEmployeeDirectory => new DbEmployeeDirectory(static fn (): Query => $c->get(Query::class))
        );
        $directory = static fn (Container $c): \Closure => static fn (): DbEmployeeDirectory => $c->get(DbEmployeeDirectory::class);

        $container->share(
            UserMapRepositoryInterface::class,
            static fn (Container $c): UserMapRepositoryInterface => new DbUserMapRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            IdentityResolver::class,
            static fn (Container $c): IdentityResolver => new IdentityResolver(
                $c->get(UserMapRepositoryInterface::class),
                $directory($c),
                $c->get(AuditLogInterface::class),
                $c->get(JoomlaLogger::class),
                $c->get(SettingsRepository::class)->load()->autoMatch(),
                null,
                new DbUserFieldReader($c->get(DatabaseInterface::class), $c->get(SettingsRepository::class)->load()->matchField()),
                $c->get(SettingsRepository::class)->load()->autoApproveMatches()
            )
        );

        $container->share(
            MappingManager::class,
            static fn (Container $c): MappingManager => new MappingManager(
                $c->get(UserMapRepositoryInterface::class),
                $directory($c),
                $c->get(AuditLogInterface::class)
            )
        );

        // Per-user cache in the component's own table: never shared between users, kept across
        // logins, and not loaded with the session on every page of the site.
        $container->share(
            UserCacheInterface::class,
            static fn (Container $c): UserCacheInterface => new DbUserCache(
                $c->get(DatabaseInterface::class),
                $c->get(JoomlaLogger::class),
                $c->get(SettingsRepository::class)->load()->cacheTtl()
            )
        );

        // The ZKBioTime server's time zone: times are shown as recorded, never shifted. The readers
        // use the same zone as AttendanceService (1.3.7: an older alias no longer turned into UTC).
        $zone = static function (Container $c): \DateTimeZone {
            $zone = $c->get(SettingsRepository::class)->load()->serverTimezone() ?: (string) Factory::getApplication()->get('offset', 'UTC');

            try {
                return new \DateTimeZone($zone);
            } catch (\Exception) {
                return new \DateTimeZone('UTC');
            }
        };

        // The zone name for PostgreSQL's AT TIME ZONE: a named zone (including older aliases PHP still
        // accepts, e.g. Asia/Calcutta, 1.3.7), never a numeric offset such as "+03:00", whose sign
        // PostgreSQL reads the other way round (POSIX); UTC otherwise.
        $dbZone = static fn (string $name): string => preg_match('~^[A-Za-z]+(?:[/_-][A-Za-z0-9+_-]+)*$~', $name) ? $name : 'UTC';

        // Attendance from ZKBioTime's database: one reader, and at most one connection, per page
        // load (the connection opens when first needed).
        $container->share(
            AttendanceReader::class,
            static function (Container $c) use ($zone, $dbZone): AttendanceReader {
                $zoneName = $zone($c)->getName();

                return new AttendanceReader(
                    static fn (): Query => $c->get(Query::class),
                    $dbZone($zoneName),
                    $c->get(SettingsRepository::class)->load()->dbDurationUnit()
                );
            }
        );

        // My Leaves and My Approvals (1.1.0): leave requests and their stages, in the server's time zone.
        $container->share(
            LeaveReader::class,
            static function (Container $c) use ($zone, $dbZone): LeaveReader {
                $zoneName = $zone($c)->getName();

                return new LeaveReader(static fn (): Query => $c->get(Query::class), $dbZone($zoneName));
            }
        );

        // Manager Dashboard (1.0.0): departments and their employees.
        $container->share(
            TeamReader::class,
            static fn (Container $c): TeamReader => new TeamReader(static fn (): Query => $c->get(Query::class))
        );

        $container->share(
            AttendanceService::class,
            static function (Container $c) use ($zone): AttendanceService {
                $settings = $c->get(SettingsRepository::class)->load();

                return new AttendanceService(
                    static fn (): AttendanceReader => $c->get(AttendanceReader::class),
                    $c->get(UserCacheInterface::class),
                    $c->get(JoomlaLogger::class),
                    $zone($c),
                    null,
                    $settings->weekendDays()
                );
            }
        );

        $container->set(
            ComponentInterface::class,
            static function (Container $c): BiotimeComponent {
                $component = new BiotimeComponent($c->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($c->get(MVCFactoryInterface::class));
                $component->setRouterFactory($c->get(RouterFactoryInterface::class));
                $component->setServiceContainer($c);

                return $component;
            }
        );
    }
};
