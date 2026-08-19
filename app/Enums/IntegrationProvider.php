<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case GoogleWorkspace = 'google_workspace';

    case SharePoint = 'sharepoint';

    /**
     * The provider name as shown to users.
     */
    public function label(): string
    {
        return match ($this) {
            self::GoogleWorkspace => 'Google Workspace',
            self::SharePoint => 'Microsoft SharePoint',
        };
    }
}
