<?php
/**
 *  Copyright (C) 2017 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SURFnet\VPN\Web\Http;

use DateInterval;
use DateTime;
use SURFnet\VPN\Web\Http\Exception\SessionException;

class Session extends Cookie
{
    /** @var array */
    private $sessionOptions;

    /**
     * @param array $sessionOptions
     */
    public function __construct(array $sessionOptions = [])
    {
        $this->sessionOptions = array_merge(
            [
                'Domain' => null,       // also bind session to Domain
                'Path' => null,         // also bind session to Path
            ],
            $sessionOptions
        );

        parent::__construct($sessionOptions);

        if (PHP_SESSION_ACTIVE !== session_status()) {
            session_start();
        }

        $this->sessionCanary();
        $this->domainBinding();
        $this->pathBinding();

        $this->replace(session_name(), session_id());
    }

    public function regenerate($deleteOldSession = false)
    {
        session_regenerate_id($deleteOldSession);
        $this->replace(session_name(), session_id());
    }

    private function sessionCanary()
    {
        $dateTime = new DateTime();
        if (!array_key_exists('Canary', $_SESSION)) {
            $_SESSION = [];
            $this->regenerate(true);
            $_SESSION['Canary'] = $dateTime->format('Y-m-d H:i:s');
        } else {
            $canaryDateTime = new DateTime($_SESSION['Canary']);
            $canaryDateTime->add(new DateInterval('PT01H'));
            if ($canaryDateTime < $dateTime) {
                $this->regenerate(true);
                $_SESSION['Canary'] = $dateTime->format('Y-m-d H:i:s');
            }
        }
    }

    private function domainBinding()
    {
        $this->sessionBinding('Domain');
    }

    private function pathBinding()
    {
        $this->sessionBinding('Path');
    }

    private function sessionBinding($key)
    {
        if (!is_null($this->sessionOptions[$key])) {
            if (!array_key_exists($key, $_SESSION)) {
                $_SESSION[$key] = $this->sessionOptions[$key];
            }
            if ($this->sessionOptions[$key] !== $_SESSION[$key]) {
                throw new SessionException(sprintf('session bound to %s "%s", expected "%s"', $key, $_SESSION[$key], $this->sessionOptions[$key]));
            }
        }
    }
}
