<?php

namespace szenario\craftobservatory\models;

use craft\base\Model;

/**
 * Analytics source settings.
 *
 * @author szenario
 * @since 1.0.0
 */
class Settings extends Model
{
    // Const Properties
    // =========================================================================

    public const SOURCE_POSTHOG = 'posthog';
    public const SOURCE_UMAMI = 'umami';

    // Public Properties
    // =========================================================================

    /**
     * Selected analytics provider.
     */
    public string $analyticsSource = self::SOURCE_POSTHOG;

    /**
     * PostHog app host.
     */
    public string $posthogHost = 'https://eu.posthog.com';

    /**
     * PostHog project ID.
     */
    public string $posthogProjectId = '';

    /**
     * PostHog personal API key with query:read scope.
     */
    public string $posthogPersonalApiKey = '';

    /**
     * Legacy Umami API URL.
     */
    public string $umamiUrl = 'https://api.umami.is';

    /**
     * Legacy Umami API key.
     */
    public string $umamiApiKey = '';

    /**
     * Legacy Umami website ID.
     */
    public string $umamiWebsiteId = '';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['analyticsSource'], 'required'],
            [['analyticsSource'], 'in', 'range' => [self::SOURCE_POSTHOG, self::SOURCE_UMAMI]],
            [['posthogHost', 'posthogProjectId', 'posthogPersonalApiKey'], 'required', 'when' => fn(self $model, string $_attribute): bool => $model->analyticsSource === self::SOURCE_POSTHOG],
            [['umamiUrl', 'umamiApiKey', 'umamiWebsiteId'], 'required', 'when' => fn(self $model, string $_attribute): bool => $model->analyticsSource === self::SOURCE_UMAMI],
        ];
    }
}
