<?php

namespace App\Launch;

/**
 * A validated launch, normalised. Both the LTI and custom-JWT paths produce this
 * and nothing downstream knows which one it came from.
 */
final class LaunchRequest
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $externalUserId,
        public readonly string $jti,
        public readonly string $nonce,
        public readonly string $messageType,
        public readonly string $role,
        public readonly ?string $externalName = null,
        /** A claim by the client about a third party. Display only. Never authenticate on it. */
        public readonly ?string $externalEmail = null,
        public readonly ?string $externalContextId = null,
        public readonly ?string $contextTitle = null,
        public readonly ?string $externalResourceLinkId = null,
        public readonly ?string $courseCode = null,
        public readonly ?string $courseNodeId = null,
    ) {}
}
