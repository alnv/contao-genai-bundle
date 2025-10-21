<?php

namespace Alnv\GenaiBundle\Helpers;

use Contao\FrontendUser;

class Toolkit
{

    public static function isCommaSeparatedList(string $strText): bool
    {

        if (\trim($strText) === '') {
            return false;
        }

        if (\strpos($strText, ',,') !== false) {
            return false;
        }

        $arrItems = \explode(',', $strText);

        foreach ($arrItems as $strItem) {

            if (\trim($strItem) === '') {
                return false;
            }

            if (\strlen($strItem) > 64) {
                return false;
            }
        }

        return true;
    }

    public static function getUserName(): string
    {

        $strName = 'Gast';
        $objFrontendUser = FrontendUser::getInstance();

        if ($objFrontendUser?->id) {
            $strName = ($objFrontendUser->firstname ? $objFrontendUser->firstname . ' ' : '') . $objFrontendUser->lastname ?: '';
        }

        if (!$strName) {
            $strName = $objFrontendUser->username;
        }

        return $strName;
    }
}