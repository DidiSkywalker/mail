<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Listener;

use DateInterval;
use DateTimeImmutable;
use Horde_Imap_Client;
use OCA\Mail\BackgroundJob\NewMessageClassificationJob;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Events\NewMessagesSynchronized;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\Mail\Service\AiIntegrations\AiIntegrationsService;
use Psr\Log\LoggerInterface;
use OCP\TextProcessing\FreePromptTaskType;
use OCA\Mail\Service\Classification\ClassificationSettingsService;


/**
 * @template-implements IEventListener<Event|NewMessagesSynchronized>
 */
class NewMessageClassificationListener implements IEventListener
{
	private const EXEMPT_FROM_CLASSIFICATION = [
		Horde_Imap_Client::SPECIALUSE_ARCHIVE,
		Horde_Imap_Client::SPECIALUSE_DRAFTS,
		Horde_Imap_Client::SPECIALUSE_JUNK,
		Horde_Imap_Client::SPECIALUSE_SENT,
		Horde_Imap_Client::SPECIALUSE_TRASH,
	];

	public function __construct(
		private IMailManager $mailManager,
		private IJobList $jobList,
		private AiIntegrationsService $aiService,
    private LoggerInterface $logger,
    private ClassificationSettingsService $classificationSettingsService,

		) 	{}

	public function handle(Event $event): void
	{
		$this->logger->info('Handling event!');
		
		if (!($event instanceof NewMessagesSynchronized)) {
			$this->logger->info('Event is not an instance of NewMessagesSynchronized');
			return;
		}

    if (!$this->classificationSettingsService->isClassificationEnabled($event->getAccount()->getUserId())) {
			return;
		}

		if (!$this->aiService->isLlmProcessingEnabled()) {
			return;
		}

		if (!$this->aiService->isLlmAvailable(FreePromptTaskType::class)) {
			return;
		}

		foreach (self::EXEMPT_FROM_CLASSIFICATION as $specialUse) {
			if ($event->getMailbox()->isSpecialUse($specialUse)) {
				$this->logger->info('Mailbox is exempt from classification: ' . $$event->getMailbox()->getRemoteId());
				// Nothing to do then
				return;
			}
		}

		$uid = $event->getAccount()->getUserId();
		// Do not process emails older than 14D to save some processing power
		$notBefore = (new DateTimeImmutable('now'))
			->sub(new DateInterval('P14D'));
		foreach ($event->getMessages() as $message) {
			if ($message->getSentAt() < $notBefore->getTimestamp()) {
				continue;
			}

			if (sizeof($message->getTags()) !== 0) {
				$this->logger->info('Mail already was tagged');
				continue;
			}

			$jobArguments = [
				NewMessageClassificationJob::PARAM_MESSAGE_ID => $message->getMessageId(),
				NewMessageClassificationJob::PARAM_MAILBOX_ID => $message->getMailboxId(),
				NewMessageClassificationJob::PARAM_USER_ID => $uid,
			];

			$this->jobList->add(NewMessageClassificationJob::class, $jobArguments);
			$this->logger->info('Added job!');
		}
	}
}
