<?php
/**
 * Console service for sending due date reminders.
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2013-2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind
 * @package  Service
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
namespace FinnaConsole\Service;

use Zend\ServiceManager\ServiceManager;
use Zend\View\Resolver\AggregateResolver;
use Zend\View\Resolver\TemplatePathStack;

/**
 * Console service for sending due date reminders.
 *
 * @category VuFind
 * @package  Service
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
class DueDateReminders extends AbstractService
{
    /**
     * Date format for due dates in database.
     */
    const DUE_DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Current view local configuration directory.
     *
     * @var string
     */
    protected $baseDir = null;

    /**
     * Base directory for all views.
     *
     * @var string
     */
    protected $viewBaseDir = null;

    /**
     * ILS connection.
     *
     * @var \Finna\ILS\Connection
     */
    protected $catalog = null;

    /**
     * Main configuration
     *
     * @var \Zend\Config\Config
     */
    protected $mainConfig = null;

    /**
     * Datasource configuration
     *
     * @var \Zend\Config\Config
     */
    protected $datasourceConfig = null;

    /**
     * Table for user accounts
     *
     * @var \VuFind\Config
     */
    protected $configReader = null;

    /**
     * Due date reminders table
     *
     * @var \Finna\Db\Table\DueDateReminder
     */
    protected $dueDateReminderTable = null;

    /**
     * User account table
     *
     * @var \Finna\Db\Table\User
     */
    protected $userTable = null;

    /**
     * Translator
     *
     * @var \VuFind\Translator
     */
    protected $translator = null;

    /**
     * Translator
     *
     * @var \VuFind\Translator
     */
    protected $recordLoader = null;

    /**
     * Translator
     *
     * @var \VuFind\Translator
     */
    protected $urlHelper = null;

    /**
     * HMAC
     *
     * @var \VuFind\HMAC
     */
    protected $hmac = null;

    /**
     * View renderer
     *
     * @var Zend\View\Renderer\PhpRenderer
     */
    protected $renderer = null;

    /**
     * Current institution.
     *
     * @var string
     */
    protected $currentInstitution = null;

    /**
     * Current institution configuration.
     *
     * @var array
     */
    protected $currentSiteConfig = null;

    /**
     * Current view path.
     *
     * @var string
     */
    protected $currentViewPath = null;

    /**
     * ServiceManager
     *
     * ServiceManager is used for creating VuFind\Mailer objects as needed
     * (mailer is not shared as its connection might time out otherwise).
     *
     * @var ServiceManager
     */
    protected $serviceManager = null;

    /**
     * Constructor
     *
     * @param Finna\Db\Table\User            $userTable            User table.
     * @param Finna\Db\Table\DueDateReminder $dueDateReminderTable Due date
     *                                                             reminder table.
     * @param VuFind\ILS\Connection          $catalog              ILS connection.
     * @param VuFind\Config                  $configReader         Config reader.
     * @param Zend\View\Renderer\PhpRenderer $renderer             View renderer.
     * @param VuFind\RecordLoader            $recordLoader         Record loader.
     * @param VuFind\HMAC                    $hmac                 HMAC.
     * @param VuFind\Translator              $translator           Translator.
     * @param ServiceManager                 $serviceManager       Service manager.
     */
    public function __construct(
        $userTable, $dueDateReminderTable, $catalog, $configReader,
        $renderer, $recordLoader, $hmac, $translator, $serviceManager
    ) {
        $this->userTable = $userTable;
        $this->dueDateReminderTable = $dueDateReminderTable;
        $this->catalog = $catalog;
        $this->mainConfig = $configReader->get('config');
        $this->datasourceConfig = $configReader->get('datasources');
        $this->configReader = $configReader;
        $this->renderer = $renderer;
        $this->translator = $translator;
        $this->urlHelper = $renderer->plugin('url');
        $this->recordLoader = $recordLoader;
        $this->hmac = $hmac;
        $this->serviceManager = $serviceManager;
    }

    /**
     * Run service.
     *
     * @param array $arguments Command line arguments.
     *
     * @return boolean success
     */
    public function run($arguments)
    {
        $this->msg('Sending due date reminders');

        if (count($arguments) < 2) {
            $this->msg($this->getUsage());
            return false;
        } else {
            $this->collectScriptArguments($arguments);
        }

        $users = $this->userTable->getUsersWithDueDateReminders();
        $this->msg('Processing ' . count($users) . ' users');

        foreach ($users as $user) {
            $remindLoans = $this->getReminders($user);
            if ($remindCnt = count($remindLoans)) {
                $this->msg(
                    "$remindCnt new loans to remind for user {$user->username}"
                    . " (id {$user->id})"
                );
                $this->sendReminder($user, $remindLoans);
            } else {
                $this->msg(
                    "No loans to remind for user {$user->username} (id {$user->id})"
                );
            }
        }
        return true;
    }

    /**
     * Get reminders for a user.
     *
     * @param \Finna\Db\Table\Row\User $user User.
     *
     * @return array Array of loans to be reminded.
     */
    protected function getReminders($user)
    {
        if (!$user->email || trim($user->email) == '') {
            $this->warn(
                "User {$user->username} (id {$user->id})"
                . ' does not have an email address, bypassing due date reminders'
            );
            return false;
        }

        $remindLoans = [];
        foreach ($user->getLibraryCards() as $card) {
            if (!$card['id']) {
                continue;
            }
            $patron = null;
            $card = $user->getLibraryCard($card['id']);
            try {
                $patron = $this->catalog->patronLogin(
                    $card['cat_username'], $card['cat_password']
                );
            } catch (\Exception $e) {
                $this->err('Catalog login error: ' . $e->getMessage());
            }

            if (!$patron) {
                $this->warn(
                    "Catalog login failed for user {$user->username}"
                    . " (id {$user->id}), card {$card->cat_username}"
                    . " (id {$card->id})"
                );
                continue;
            }
            $todayTime = new \DateTime();
            try {
                $loans = $this->catalog->getMyTransactions($patron);
            } catch (\Exception $e) {
                $this->err(
                    "Exception trying to get loans for user {$user->username}"
                    . " (id {$user->id}), card {$card->cat_username}"
                    . " (id {$card->id}): "
                    . $e->getMessage()
                );
                continue;
            }
            foreach ($loans as $loan) {
                $dueDate = new \DateTime($loan['duedate']);
                $dayDiff = $dueDate->diff($todayTime)->days;
                if ($todayTime >= $dueDate
                    || $dayDiff <= $user->finna_due_date_reminder
                ) {
                    $params = [
                       'user_id' => $user->id,
                       'loan_id' => $loan['item_id'],
                       'due_date'
                          => $dueDate->format($this::DUE_DATE_FORMAT)
                    ];

                    $reminder = $this->dueDateReminderTable->select($params);
                    if (count($reminder)) {
                        // Reminder already sent
                        continue;
                    }

                    // Store also title for display in email
                    $title = isset($loan['title'])
                        ? $loan['title']
                        : null;

                    if (isset($loan['id'])) {
                        $record = $this->recordLoader->load(
                            $loan['id'], 'Solr', true
                        );
                    }

                    $dateFormat = isset(
                        $this->currentSiteConfig['Site']['displayDateFormat']
                    )
                        ? $this->currentSiteConfig['Site']['displayDateFormat']
                        : $this->mainConfig->Site->displayDateFormat;

                    $remindLoans[] = [
                        'loanId' => $loan['item_id'],
                        'dueDate' => $loan['duedate'],
                        'dueDateFormatted' => $dueDate->format($dateFormat),
                        'title' => $title,
                        'record' => $record
                    ];
                }
            }
        }
        return $remindLoans;
    }

    /**
     * Send reminders for a user.
     *
     * @param \Finna\Db\Table\Row\User $user        User.
     * @param array                    $remindLoans Loans to be reminded.
     *
     * @return boolean success.
     */
    protected function sendReminder($user, $remindLoans)
    {
        if (!$user->email || trim($user->email) == '') {
            $this->msg(
                "User {$user->username} (id {$user->id})"
                . ' does not have an email address, bypassing due date reminders'
            );
            return false;
        }

        list($userInstitution,) = explode(':', $user['username'], 2);

        if (!$this->currentInstitution
            || $userInstitution != $this->currentInstitution
        ) {
            $templateDirs = [
                "{$this->baseDir}/themes/finna/templates",
            ];
            if (!$viewPath = $this->resolveViewPath($userInstitution)) {
                $this->err(
                    "Could not resolve view path for user {$user->username}"
                    . " (id {$user->id})"
                );
                return false;
            } else {
                $templateDirs[] = "$viewPath/themes/custom/templates";
            }
            $this->currentInstitution = $userInstitution;
            $this->currentViewPath = $viewPath;

            $resolver = new AggregateResolver();
            $this->renderer->setResolver($resolver);
            $stack = new TemplatePathStack(['script_paths' => $templateDirs]);
            $resolver->attach($stack);

            $siteConfig = $viewPath . '/local/config/vufind/config.ini';
            $this->currentSiteConfig = parse_ini_file($siteConfig, true);
        }

        $language = isset($this->currentSiteConfig['Site']['language'])
            ? $this->currentSiteConfig['Site']['language'] : 'fi';
        $validLanguages = array_keys($this->currentSiteConfig['Languages']);
        if (!empty($user->finna_language)
            && in_array($user->finna_language, $validLanguages)
        ) {
            $language = $user->finna_language;
        }
        $this->translator
            ->addTranslationFile('ExtendedIni', null, 'default', $language)
            ->setLocale($language);

        $key = $this->dueDateReminderTable->getUnsubscribeSecret(
            $this->hmac, $user, $user->id
        );
        $params = [
            'id' => $user->id,
            'type' => 'reminder',
            'key' => $key
        ];
        $unsubscribeUrl
            = $this->urlHelper->__invoke('myresearch-unsubscribe')
            . '?' . http_build_query($params);

        $urlParts = explode('/', $this->currentViewPath);
        $urlView = array_pop($urlParts);
        $urlInstitution = array_pop($urlParts);

        $baseUrl = 'https://' . $urlInstitution . '.finna.fi';
        if ($urlView != $this::DEFAULT_PATH) {
            $baseUrl .= "/$urlView";
        }
        $params = [
             'loans' => $remindLoans,
             'url' => $baseUrl . $this->urlHelper->__invoke('myresearch-checkedout'),
             'unsubscribeUrl' => $baseUrl . $unsubscribeUrl,
             'baseUrl' => $baseUrl
        ];
        $subject = $this->translator->translate('due_date_email_subject');
        $message = $this->renderer->render("Email/due-date-reminder.phtml", $params);
        try {
            $to = $user->email;
            $from = $this->currentSiteConfig['Site']['email'];
            $this->serviceManager->get('VuFind\Mailer')->send(
                $to, $from, $subject, $message
            );
        } catch (\Exception $e) {
            $this->err(
                "Failed to send due date reminders to user {$user->username} "
                . " (id {$user->id})"
            );
            $this->err('   ' . $e->getMessage());
            return false;
        }

        foreach ($remindLoans as $loan) {
            $params = ['user_id' => $user->id, 'loan_id' => $loan['loanId']];
            $this->dueDateReminderTable->delete($params);

            $dueDate = new \DateTime($loan['dueDate']);
            $params['due_date'] = $dueDate->format($this::DUE_DATE_FORMAT);
            $params['notification_date'] = date($this::DUE_DATE_FORMAT, time());

            $this->dueDateReminderTable->insert($params);
        }

        return true;
    }

    /**
     * Collect command line arguments.
     *
     * @param array $arguments Arguments
     *
     * @return void
     */
    protected function collectScriptArguments($arguments)
    {
        // Current view local configuration directory
        $this->baseDir = isset($arguments[0]) ? $arguments[0] : false;
        // Base directory for all views.
        $this->viewBaseDir = isset($arguments[1]) ? $arguments[1] : '..';
    }

    /**
     * Get usage information.
     *
     * @return string
     */
    protected function getUsage()
    {
// @codingStandardsIgnoreStart
        return <<<EOT
Usage:
  php index.php util due_date_reminders <vufind_dir> <view_dir>

  Sends due date reminders.
    vufind_dir    VuFind base installation directory
    view_dir      View directory

EOT;
// @codingStandardsIgnoreEnd
    }
}
