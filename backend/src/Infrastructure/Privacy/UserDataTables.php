<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\Privacy\UserDataTable;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * THE list of the tables that hold data of a user. The data export (FR-ACC-07) and account
 * deletion (FR-ACC-08) read it and nothing else.
 *
 * A build step that adds a table with user data adds one entry to registry():
 *
 *     new UserDataTable('smoke_logs', 'smokeLogs', new SmokeLogsExporter($this->sql), new RowsOfUser($this->sql, 'smoke_logs')),
 *
 * with
 * - the table name,
 * - the member of the export document (and its schema in AccountExport of api/openapi.yaml),
 * - an exporter: a small class next to this one that selects the user's rows and names the columns
 *   as the schema does (never a secret column),
 * - an eraser: RowsOfUser when every row belongs to one user alone, RowsOfEitherUser when a row
 *   belongs to two users in two columns (a friendship, a block), otherwise a class of its own,
 * - for a table whose rows a user has in two roles, further members with their exporters (nudges).
 *
 * The order is the order of erasure: a table comes before the tables its rows refer to.
 * UserDataGuardTest fails for a table that refers to users and is not listed here, and
 * UserDataTablesTest for a column that is neither exported nor deliberately left out.
 */
final readonly class UserDataTables
{
    public function __construct(private Sql $sql)
    {
    }

    public function registry(): UserDataRegistry
    {
        return new UserDataRegistry([
            new UserDataTable('consents', 'consents', new ConsentsExporter($this->sql), new RowsOfUser($this->sql, 'consents')),
            new UserDataTable('plans', 'plan', new PlanExporter($this->sql), new RowsOfUser($this->sql, 'plans')),
            new UserDataTable('plan_history', 'planHistory', new PlanHistoryExporter($this->sql), new RowsOfUser($this->sql, 'plan_history')),
            new UserDataTable('baseline_history', 'baselineHistory', new BaselineHistoryExporter($this->sql), new RowsOfUser($this->sql, 'baseline_history')),
            new UserDataTable('timezone_history', 'timeZoneHistory', new TimeZoneHistoryExporter($this->sql), new RowsOfUser($this->sql, 'timezone_history')),
            new UserDataTable('smoke_logs', 'smokeLogs', new SmokeLogsExporter($this->sql), new RowsOfUser($this->sql, 'smoke_logs')),
            new UserDataTable('day_summaries', 'days', new DaysExporter($this->sql), new RowsOfUser($this->sql, 'day_summaries')),
            new UserDataTable('user_progress', 'progress', new ProgressExporter($this->sql), new RowsOfUser($this->sql, 'user_progress')),
            new UserDataTable('xp_events', 'xpEvents', new XpEventsExporter($this->sql), new RowsOfUser($this->sql, 'xp_events')),
            new UserDataTable('refresh_tokens', 'sessions', new SessionsExporter($this->sql), new RowsOfUser($this->sql, 'refresh_tokens')),
            new UserDataTable('one_time_tokens', 'mailLinks', new MailLinksExporter($this->sql), new RowsOfUser($this->sql, 'one_time_tokens')),
            new UserDataTable('mail_outbox', 'queuedMails', new QueuedMailsExporter($this->sql), new RowsOfUser($this->sql, 'mail_outbox')),
            // Friends (HLD §4.7). A friendship and a block belong to both users: deleting an account
            // deletes every one it is part of, on either side.
            new UserDataTable('invite_codes', 'inviteCode', new InviteCodeExporter($this->sql), new RowsOfUser($this->sql, 'invite_codes')),
            new UserDataTable('friendships', 'friends', new FriendsExporter($this->sql), new RowsOfEitherUser($this->sql, 'friendships', 'user_a', 'user_b')),
            new UserDataTable('blocks', 'blockedUsers', new BlockedUsersExporter($this->sql), new RowsOfEitherUser($this->sql, 'blocks', 'blocker_id', 'blocked_id')),
            // Duels (HLD §4.8). A duel stays for the other user: the eraser empties the side of the
            // deleted user, deletes the proposals and turns running duels into forfeits.
            new UserDataTable('duels', 'duels', new DuelsExporter($this->sql), new DuelsOfUser($this->sql)),
            // Leagues (HLD §4.9). A result belongs to its user alone. A friend's stored rank stays.
            new UserDataTable('league_results', 'leagueResults', new LeagueResultsExporter($this->sql), new RowsOfUser($this->sql, 'league_results')),
            // Badges (HLD §4.10). A badge belongs to its user alone.
            new UserDataTable('user_badges', 'badges', new BadgesExporter($this->sql), new RowsOfUser($this->sql, 'user_badges')),
            // Health milestones for friends, and the settings (HLD §4.11). Each row belongs to its user alone.
            new UserDataTable('user_milestones', 'healthMilestones', new HealthMilestonesExporter($this->sql), new RowsOfUser($this->sql, 'user_milestones')),
            new UserDataTable('user_settings', 'settings', new SettingsExporter($this->sql), new RowsOfUser($this->sql, 'user_settings')),
            // Reactions (HLD §4.13): those the user sent, and those of others to the user's entries.
            // Before the feed entries, which its eraser needs to find the reactions to them.
            new UserDataTable('feed_reactions', 'reactionsSent', new ReactionsSentExporter($this->sql), new ReactionsOfUser($this->sql)),
            // Activity feed (HLD §4.12). An entry belongs to its user; another user's entry that names
            // the user as the opponent of a duel stays and forgets the id.
            new UserDataTable('feed_events', 'feedEvents', new FeedEventsExporter($this->sql), new FeedEventsOfUser($this->sql)),
            // Nudges (HLD §4.14). A nudge belongs to its sender and to its receiver: deleting an account
            // deletes the ones it sent and received. Two members: the nudges the user sent, without
            // whether they were delivered, and those the user received, with it.
            new UserDataTable(
                'nudges',
                'nudgesSent',
                new NudgesSentExporter($this->sql),
                new RowsOfEitherUser($this->sql, 'nudges', 'from_user_id', 'to_user_id'),
                ['nudgesReceived' => new NudgesReceivedExporter($this->sql)],
            ),
        ]);
    }
}
