<?php
/**
 * Record Controller
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015.
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
 * @package  Controller
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
namespace Finna\Controller;
use VuFind\Search\Memory, Zend\Mail as Mail;

/**
 * Record Controller
 *
 * @category VuFind
 * @package  Controller
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class RecordController extends \VuFind\Controller\RecordController
{
    use RecordControllerTrait;
    use CatalogLoginTrait;

    /**
     * Create record feedback form and send feedback to correct recipient.
     *
     * @return \Zend\View\Model\ViewModel
     * @throws \Exception
     */
    public function feedbackAction()
    {
        $view = $this->createViewModel();

        if ($this->formWasSubmitted('submitFeedback')) {
            $flashMsg = $this->flashMessenger();

            $message = $this->params()->fromPost('feedback_message');
            $senderEmail = $this->params()->fromPost('from');
            $validator = new \Zend\Validator\EmailAddress();
            if (!$validator->isValid($senderEmail)) {
                $flashMsg->setNamespace('error')
                    ->addMessage('Email address is invalid');
                return $view;
            }

            $driver = $this->loadRecord();
            $dataSource = $driver->getDataSource();
            $dataSources = $this->getServiceLocator()->get('VuFind\Config')
                ->get('datasources');

            $inst = isset($dataSources->$dataSource) ?
                $dataSources->$dataSource : null;
            $recipientEmail = isset($inst->feedbackEmail) ?
                $inst->feedbackEmail : null;
            if ($recipientEmail == null) {
                throw new \Exception(
                    'Feedback Module Error:'
                    . 'Recipient Email Unset (see datasources.ini)'
                );
            }

            $emailSubject = $this->translate(
                'feedback_on_record',
                ['%%record%%' => $driver->getBreadcrumb()]
            );
            $serverUrl = $this->getRequest()->getServer('REQUEST_SCHEME');
            $serverUrl .= '://' . $this->getRequest()->getServer('HTTP_HOST');

            $emailMessage = "\n" . $this->translate('This email was sent from');
            $emailMessage .= ": " . $senderEmail . "\n";
            $emailMessage .=
                "------------------------------------------------------------\n";
            // Use the record plugin to render the template for the correct driver
            $recordPlugin = $this->getViewRenderer()->plugin('record');
            $emailMessage .= $recordPlugin($driver)->getEmail();
            $emailMessage .=
                "\n\n------------------------------------------------------------\n";
            if (!empty($message)) {
                $emailMessage .= "\n" . $this
                    ->translate('Message From Sender') . ":\n";
                $emailMessage .= "\n" . $message . "\n\n";
            }

            // This sets up the email to be sent
            $mail = new Mail\Message();
            $mail->setEncoding('UTF-8');
            $mail->setBody($emailMessage);
            $mail->setFrom($senderEmail);
            $mail->addTo($recipientEmail);
            try {
                $mail->setSubject($emailSubject);
            } catch (\Exception $e) {
                // Uhh.. PHP bug https://bugs.php.net/bug.php?id=53891 causes trouble
                // when trying to encode a subject containing non-ascii characters.
                // Try to convert the subject to ascii..
                // TODO: Remove this when PHP works properly..
                $emailSubject = iconv('UTF-8', 'ascii//TRANSLIT', $emailSubject);
                $mail->setSubject($emailSubject);
            }
            $headers = $mail->getHeaders();
            $headers->removeHeader('Content-Type');
            $headers->addHeaderLine('Content-Type', 'text/plain; charset=UTF-8');

            $this->getServiceLocator()->get('VuFind\Mailer')->getTransport()
                ->send($mail);

            $flashMsg->addSuccessMessage('Thank you for your feedback.');
            if ($this->getRequest()->getQuery('layout', 'no') !== 'lightbox'
                || 'layout/lightbox' != $this->layout()->getTemplate()
            ) {
                $this->redirectToRecord('');
            }
        }

        return $view;
    }

    /**
     * Load a normalized record from RecordManager for preview
     *
     * @param string $data   Record Metadata
     * @param string $format Metadata format
     * @param string $source Data source
     *
     * @return AbstractRecordDriver
     * @throw  \Exception
     */
    protected function loadPreviewRecord($data, $format, $source)
    {
        $config = $this->getConfig();
        if (empty($config->NormalizationPreview->url)) {
            throw new \Exception('Normalization preview URL not configured');
        }

        $httpService = $this->serviceLocator->get('\VuFind\Http');
        $client = $httpService->createClient(
            $config->NormalizationPreview->url,
            \Zend\Http\Request::METHOD_POST
        );
        $client->setParameterPost(
            ['data' => $data, 'format' => $format, 'source' => $source]
        );
        $response = $client->send();
        if (!$response->isSuccess()) {
            throw new \Exception(
                'Failed to load preview: ' . $response->getStatusCode() . ' '
                . $response->getReasonPhrase()
            );
        }
        $metadata = json_decode($response->getBody(), true);
        $recordFactory = $this->serviceLocator
            ->get('VuFind\RecordDriverPluginManager');
        $this->driver = $recordFactory->getSolrRecord($metadata);
        return $this->driver;
    }

    /**
     * Load the record requested by the user; note that this is not done in the
     * init() method since we don't want to perform an expensive search twice
     * when homeAction() forwards to another method.
     *
     * @return AbstractRecordDriver
     */
    protected function loadRecord()
    {
        $id = $this->params()->fromRoute('id', $this->params()->fromQuery('id'));
        // 0 = preview record
        if ($id != '0') {
            return parent::loadRecord();
        }
        $data = $this->params()->fromPost(
            'data', $this->params()->fromQuery('data', '')
        );
        $format = $this->params()->fromPost(
            'format', $this->params()->fromQuery('format', '')
        );
        $source = $this->params()->fromPost(
            'source', $this->params()->fromQuery('source', '')
        );
        if (!$data) {
            // Support marc parameter for Voyager compatibility
            $format = 'marc';
            if (!$source) {
                $source = '_marc_preview';
            }
            $data = $this->params()->fromPost(
                'marc', $this->params()->fromQuery('marc')
            );
            // For some strange reason recent Voyager versions double-encode the data
            // with encodeURIComponent
            if (substr($data, -3) == '%1D') {
                $data = urldecode($data);
            }
            // Voyager doesn't tell the proper encoding, so it's up to the browser to
            // decide. Try to handle both UTF-8 and ISO-8859-1.
            $len = (int)substr($data, 0, 5);
            if (strlen($data) != $len) {
                $data = $this->decodeVoyagerUTF8($data);
            }
            $marc = new \File_MARC($data, \File_MARC::SOURCE_STRING);
            $record = $marc->next();
            if (false === $record) {
                throw new \Exception('Missing record data');
            }
            $data = $record->toXML();
            $data = preg_replace('/[\x00-\x09,\x11,\x12,\x14-\x1f]/', '', $data);
            $data = iconv('UTF-8', 'UTF-8//IGNORE', $data);
        }
        if (!$data || !$format || !$source) {
            throw new \Exception('Missing parameters');
        }

        return $this->loadPreviewRecord($data, $format, $source);
    }

    /**
     * Display a particular tab.
     *
     * @param string $tab  Name of tab to display
     * @param bool   $ajax Are we in AJAX mode?
     *
     * @return mixed
     */
    protected function showTab($tab, $ajax = false)
    {
        // Special case -- handle lightbox login request if login has already been
        // done
        if ($this->params()->fromQuery('layout', 'false') == 'lightbox'
            && $this->params()->fromQuery('catalogLogin', 'false') == 'true'
            && is_array($patron = $this->catalogLogin())
        ) {
            $response = $this->getResponse();
            $response->setStatusCode(205);
            return $response;
        }
        return parent::showTab($tab, $ajax);
    }

    /**
     * Decode double-encoded UTF-8 received from Voyager
     *
     * @param string $str String to decode
     *
     * @return string
     */
    protected function decodeVoyagerUTF8($str)
    {
        $result = '';
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $c1 = ord($str[$i]);
            $c2 = $i + 1 < $len ? ord($str[$i + 1]) : 0;
            $c3 = $i + 2 < $len ? ord($str[$i + 2]) : 0;
            $c4 = $i + 3 < $len ? ord($str[$i + 3]) : 0;
            $c5 = $i + 4 < $len ? ord($str[$i + 4]) : 0;
            $c6 = $i + 5 < $len ? ord($str[$i + 5]) : 0;

            if ($c1 < 0x80) {
                $result .= chr($c1);
            } elseif ($c1 < 0xE0) {
                $c = (($c1 & 0x1F) << 6) + ($c2 & 0x3F);
                $result .= chr($c);
                $i += 1;
            } elseif ($c1 < 0xF0) {
                $c = (($c1 & 0x0F) << 12) + (($c2 & 0x3F) << 6) + ($c3 & 0x3F);
                $result .= chr($c);
                $i += 2;
            } elseif ($c1 < 0xF8) {
                $c = (($c1 & 0x07) << 18) + (($c2 & 0x3F) << 12)
                    + (($c3 & 0x3F) << 6) + ($c4 & 0x3F);
                $result .= chr($c);
                $i += 3;
            } elseif ($c1 < 0xFC) {
                $c = (($c1 & 0x03) << 24) + (($c2 & 0x3F) << 18)
                    + (($c3 & 0x3F) << 12) + (($c4 & 0x3F) << 6) + ($c5 & 0x3F);
                $result .= chr($c);
                $i += 4;
            } elseif ($c1 < 0xFE) {
                $c = (($c1 & 0x01) << 30) + (($c2 & 0x3F) << 24)
                    + (($c3 & 0x3F) << 18) + (($c4 & 0x3F) << 12)
                    + (($c5 & 0x3F) << 6) + ($c6 & 0x3F);
                $result .= chr($c);
                $i += 5;
            } else {
                $c = chr(0xFF);
            }
        }
        return $result;
    }
}
