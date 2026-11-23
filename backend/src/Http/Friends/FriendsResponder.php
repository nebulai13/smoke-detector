<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Friends;

use DateTimeImmutable;
use DateTimeZone;
use SmokeDetector\Application\Friends\BlockedUser;
use SmokeDetector\Application\Friends\FriendOverview;
use SmokeDetector\Application\Friends\FriendView;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\PendingRequest;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\Friends\SentRequest;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;

/**
 * Writes invite code, friends, requests and blocks as the schemas Invite, PublicUser, Friend,
 * FriendRequest, FriendOverview, FriendRequestSent and BlockedUserList of api/openapi.yaml.
 *
 * What one user sees of another is built from PublicProfile and FriendView only, so nothing else
 * of an account or its progress can get into these answers.
 */
final readonly class FriendsResponder
{
    /**
     * @param string $appUrl The address of the web app, without a trailing slash (Settings::$appUrl).
     */
    public function __construct(private string $appUrl)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function invite(InviteCode $code): array
    {
        // Behind a "#": a browser never sends that part to a server, so the code is in no access log.
        return ['code' => $code->value, 'url' => $this->appUrl . '/invite#' . $code->value];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(FriendOverview $overview): array
    {
        return [
            'friends' => array_map($this->friend(...), $overview->friends),
            'incoming' => array_map($this->request(...), $overview->incoming),
            'outgoing' => array_map($this->request(...), $overview->outgoing),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function friend(FriendView $friend): array
    {
        return [
            'user' => $this->user($friend->user),
            'since' => self::time($friend->since),
            'level' => $friend->level,
            'xp' => $friend->xp,
            'checkInStreak' => ['current' => $friend->checkInCurrent, 'longest' => $friend->checkInLongest],
            'underLimitStreak' => ['current' => $friend->underLimitCurrent, 'longest' => $friend->underLimitLongest],
            // The keys only: when a badge was earned is not for friends.
            'badges' => array_map(static fn (Badge $badge): string => $badge->value, $friend->badges),
            // The keys only, as the friend's setting allows: when a milestone was reached would tell the day of a session.
            'healthMilestones' => array_map(static fn (HealthMilestone $milestone): string => $milestone->value, $friend->healthMilestones),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sent(SentRequest $sent): array
    {
        return ['status' => $sent->accepted ? 'accepted' : 'pending', 'user' => $this->user($sent->user)];
    }

    /**
     * @param list<BlockedUser> $blocked
     *
     * @return array<string, mixed>
     */
    public function blocked(array $blocked): array
    {
        return [
            'blocked' => array_map(
                fn (BlockedUser $entry): array => ['user' => $this->user($entry->user), 'blockedAt' => self::time($entry->blockedAt)],
                $blocked,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(PendingRequest $request): array
    {
        return ['user' => $this->user($request->user), 'requestedAt' => self::time($request->requestedAt)];
    }

    /**
     * @return array<string, string>
     */
    private function user(PublicProfile $profile): array
    {
        return ['userId' => $profile->userId->value, 'displayName' => $profile->displayName->value, 'avatar' => $profile->avatar->key];
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
