<?php

namespace anvildev\simpleform\helpers;

/**
 * Single source of truth for splitting a raw CC/BCC address-list string
 * (#313) into candidate addresses. {@see \anvildev\simpleform\models\NotificationModel::validateAddressList}
 * and {@see \anvildev\simpleform\services\EmailService} both need the exact
 * same split — a save-time validator that accepts a separator the send-time
 * filter doesn't recognize (or vice versa) would let an unvalidated address
 * reach the mailer. Splitting is the only thing shared; validation and
 * filtering stay in their own callers.
 */
final class AddressList
{
    /**
     * Split a comma/semicolon/whitespace-separated address string into a
     * trimmed, non-empty list of candidate addresses. Does not validate that
     * each candidate is a well-formed email — callers own that check.
     *
     * @return list<string>
     */
    public static function split(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
