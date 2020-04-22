<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2020, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace SURFnet\VPN\Web;

interface TplInterface
{
    /**
     * Render the template.
     *
     * @param string $templateName      the name of the template
     * @param array  $templateVariables the variables to be used in the
     *                                  template
     *
     * @return string the rendered template
     */
    public function render($templateName, array $templateVariables);

    public function addDefault(array $templateVariables);
}
