<?php
/**
 * Multiple Backend Driver.
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015-2016.
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
 * @package  ILSdrivers
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */
namespace Finna\ILS\Driver;

use VuFind\Exception\ILS as ILSException;
use VuFind\I18n\Translator\TranslatorAwareInterface;

/**
 * Multiple Backend Driver.
 *
 * This driver allows to use multiple backends determined by a record id or
 * user id prefix (e.g. source.12345).
 *
 * @category VuFind
 * @package  ILSdrivers
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */
class MultiBackend extends \VuFind\ILS\Driver\MultiBackend
    implements TranslatorAwareInterface
{
    use \VuFind\I18n\Translator\TranslatorAwareTrait;

    /**
     * Change Password
     *
     * Attempts to change patron password (PIN code)
     *
     * @param array $details An array of patron id and old and new password
     *
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function changePassword($details)
    {
        // Remove old credentials from the cache regardless of whether the change
        // was successful
        $cacheKey = 'patron|' . $details['patron']['cat_username'];
        $item = $this->putCachedData($cacheKey, null);

        return parent::changePassword($details);
    }

    /**
     * Get available login targets (drivers enabled for login)
     *
     * @return string[] Source ID's
     */
    public function getLoginDrivers()
    {
        $drivers = parent::getLoginDrivers();
        if (!isset($this->config['General']['sort_login_drivers'])
            || $this->config['General']['sort_login_drivers']
        ) {
            usort(
                $drivers,
                function ($a, $b) {
                    $at = $this->translate("source_$a", null, $a);
                    $bt = $this->translate("source_$b", null, $b);
                    return strcmp($at, $bt);
                }
            );
        }
        return $drivers;
    }

    /**
     * Check if patron is authorized (e.g. to access electronic material).
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return bool True if patron is authorized, false if not
     */
    public function getPatronAuthorizationStatus($patron)
    {
        $source = $this->getSource($patron['cat_username']);
        $driver = $this->getDriver($source);
        if ($driver) {
            return $driver->getPatronAuthorizationStatus(
                $this->stripIdPrefixes($patron, $source)
            );
        }
        throw new ILSException('No suitable backend driver found');
    }

    /**
     * Update patron's phone number
     *
     * @param array  $patron Patron array
     * @param string $phone  Phone number
     *
     * @throws ILSException
     *
     * @return array Associative array of the results
     */
    public function updatePhone($patron, $phone)
    {
        $source = $this->getSource($patron['cat_username']);
        $driver = $this->getDriver($source);
        if ($driver
            && $this->methodSupported($driver, 'updatePhone', [$patron, $phone])
        ) {
            return $driver->updatePhone(
                $this->stripIdPrefixes($patron, $source), $phone
            );
        }
        throw new ILSException('No suitable backend driver found');
    }

    /**
     * Update patron's email address
     *
     * @param array  $patron Patron array
     * @param String $email  Email address
     *
     * @throws ILSException
     *
     * @return array Associative array of the results
     */
    public function updateEmail($patron, $email)
    {
        $source = $this->getSource($patron['cat_username']);
        $driver = $this->getDriver($source);
        if ($driver
            && $this->methodSupported($driver, 'updateEmail', [$patron, $email])
        ) {
            return $driver->updateEmail(
                $this->stripIdPrefixes($patron, $source), $email
            );
        }
        throw new ILSException('No suitable backend driver found');
    }

    /**
     * Update patron contact information
     *
     * @param array $patron  Patron array
     * @param array $details Associative array of patron contact information
     *
     * @throws ILSException
     *
     * @return array Associative array of the results
     */
    public function updateAddress($patron, $details)
    {
        $source = $this->getSource($patron['cat_username']);
        $driver = $this->getDriver($source);
        if ($driver
            && $this->methodSupported($driver, 'updateAddress', [$patron, $details])
        ) {
            return $driver->updateAddress(
                $this->stripIdPrefixes($patron, $source), $details
            );
        }
        throw new ILSException('No suitable backend driver found');
    }

    /**
     * Change Pickup Location
     *
     * Attempts to change the pickup location of a specific hold
     *
     * @param array $patron      The patron array from patronLogin
     * @param array $holdDetails The request details
     *
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function changePickupLocation($patron, $holdDetails)
    {
        $source = $this->getSource($patron['cat_username']);
        $driver = $this->getDriver($source);
        if ($driver
            && $this->methodSupported(
                $driver, 'changePickupLocation', [$patron, $holdDetails]
            )
        ) {
            return $driver->changePickupLocation(
                $this->stripIdPrefixes($patron, $source), $holdDetails
            );
        }
        throw new ILSException('No suitable backend driver found');
    }

    /**
     * Return total amount of fees that may be paid online.
     *
     * @param array $patron Patron
     *
     * @throws ILSException
     * @return array Associative array of payment info,
     * false if an ILSException occurred.
     */
    public function getOnlinePayableAmount($patron)
    {
        $source = $this->getSource($patron['cat_username']);
        $driver = $this->getDriver($source);
        if ($driver
        ) {
            return $driver->getOnlinePayableAmount(
                $this->stripIdPrefixes($patron, $source)
            );
        }
        throw new ILSException('Online payment not supported');
    }

    /**
     * Mark fees as paid.
     *
     * This is called after a successful online payment.
     *
     * @param array  $patron        Patron.
     * @param int    $amount        Amount to be registered as paid.
     * @param string $transactionId Transaction ID.
     *
     * @throws ILSException
     * @return boolean success
     */
    public function markFeesAsPaid($patron, $amount, $transactionId)
    {
        $source = $this->getSource($patron['cat_username']);
        $driver = $this->getDriver($source);
        if ($driver
            && $this->methodSupported($driver, 'markFeesAsPaid')
        ) {
            return $driver->markFeesAsPaid(
                $this->stripIdPrefixes($patron, $source), $amount, $transactionId
            );
        }
        throw new ILSException('Online payment not supported');
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string      $username  The patron user id or barcode
     * @param string      $password  The patron password
     * @param string|null $secondary Optional secondary login field
     *
     * @return mixed           Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function patronLogin($username, $password, $secondary = null)
    {
        $cacheKey = "patron|$username";
        $item = $this->getCachedData($cacheKey);
        if ($item !== null) {
            return $item;
        }

        $source = $this->getSource($username);
        if (!$source) {
            $source = $this->getDefaultLoginDriver();
        }
        $driver = $this->getDriver($source);
        if ($driver) {
            $patron = $driver->patronLogin(
                $this->getLocalId($username), $password, $secondary
            );
            $patron = $this->addIdPrefixes($patron, $source);
            if (is_array($patron)) {
                $patron['source'] = $source;
            }
            $this->putCachedData($cacheKey, $patron);
            return $patron;
        }
        throw new ILSException('No suitable backend driver found');
    }

    /**
     * Get configuration for the ILS driver.  We will load an .ini file named
     * after the driver class and number if it exists;
     * otherwise we will return an empty array.
     *
     * @param string $source The source id to use for determining the
     * configuration file
     *
     * @return array   The configuration of the driver
     */
    protected function getDriverConfig($source)
    {
        // Determine config file name based on class name:
        try {
            $config = $this->configLoader->get(
                $this->drivers[$source] . '_' . $source
            )->toArray();
            if (!empty($config)) {
                return $config;
            }
        } catch (\Zend\Config\Exception\RuntimeException $e) {
            // Fall through
        }
        return parent::getDriverConfig($source);
    }

    /**
     * Method to ensure uniform cache keys for cached VuFind objects.
     *
     * @param string|null $suffix Optional suffix that will get appended to the
     * object class name calling getCacheKey()
     *
     * @return string
     */
    protected function getCacheKey($suffix = null)
    {
        return 'MultiBackend-' . md5($suffix);
    }
}
