<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\BackgroundJob;

use Horde_Imap_Client;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Db\Tag;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\TagMapper;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\AiIntegrations\AiIntegrationsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Exception\ClientException;

class NewMessageClassificationJob extends QueuedJob {
	public const PARAM_MESSAGE_ID = 'messageId';
	public const PARAM_MAILBOX_ID = 'mailboxId';
	public const PARAM_USER_ID = 'userId';

    public function __construct(
        ITimeFactory $time,
        private TagMapper $tagMapper,
		private AccountService $accountService,
        private LoggerInterface $logger,
        private IMailManager $mailManager,
        private AiIntegrationsService $aiIntegrationsService
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $this->logger->info("Running NewMessageClassificationJob");


		$messageId = $argument[self::PARAM_MESSAGE_ID];
		$mailboxId = $argument[self::PARAM_MAILBOX_ID];
		$userId = $argument[self::PARAM_USER_ID];

        try {
			$mailbox = $this->mailManager->getMailbox($userId, $mailboxId);
			$account = $this->accountService->find($userId, $mailbox->getAccountId());
		} catch (ClientException $e) {
            $this->logger->error('Could not find mailbox / account that corresponds to the user / mailbox ids' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return;
		}

		$messages = $this->mailManager->getByMessageId($account, $messageId);
		$messages = array_filter(
			$messages,
			static fn (Message $message) => $message->getMailboxId() === $mailboxId,
		);

		if (count($messages) === 0) {
            $this->logger->info("No message to classify.");
            return;
		}

        $optionalTags = [
            Tag::LABEL_IMPORTANT,
            Tag::LABEL_LATER,
            Tag::LABEL_PERSONAL,
            Tag::LABEL_TODO,
            Tag::LABEL_WORK,
        ];

        $tags = [];

        foreach ($optionalTags as $label) {
            try {
                $tag = $this->tagMapper->getTagByImapLabel($label, $account->getUserId());
                $tags[$label] = $tag;
            } catch (DoesNotExistException $e) {
                // Log the error and continue with the next label
                $this->logger->error('Could not find tag for label ' . $label . ' for user ' . $account->getUserId() . ': ' . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }

        try {
            // Get smart tags from AI integration service
            foreach ($messages as $message) {
                if (sizeof($message->getTags()) !== 0) {
                    $this->logger->info('Mail already was tagged');
                    continue;
                }

                $smartTags = $this->aiIntegrationsService->getSmartTagsZeroShotClassifier(
                    $account,
                    $mailbox,
                    $message,
                    $userId
                );

                foreach ($smartTags as $smartTag) {
                    if (isset($tags[$smartTag])) {
                        // Apply the tag to the message
                        $this->mailManager->tagMessage($account, $mailbox->getName(), $message, $tags[$smartTag], true);
                        if ($smartTag === Tag::LABEL_IMPORTANT) {
                            // Additionally flag the message as important
                            $this->mailManager->flagMessage($account, $mailbox->getName(), $message->getUid(), Tag::LABEL_IMPORTANT, true);
                        }
                    }
                }
            }
        } catch (ServiceException $e) {
            $this->logger->error('Could not classify incoming message importance: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
