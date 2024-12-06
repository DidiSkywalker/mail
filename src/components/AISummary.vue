<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="summary">
		<div class="summary__title">
			AI Insights
		</div>
		<div class="summary__title">
			Summary
		</div>
		<div class="summary__content">
			{{ summaryText }}
		</div>

		<div class="summary__title">
			Classification: {{ classification }}
		</div>
	</div>
</template>
<script>

export default {

	name: 'AISummary',
	components: {},
	props: {
		summaryText: {
			required: true,
			type: String,
		},
		classification: {
			required: true,
			type: String,
		},
	},
	data() {
		return {
			showMore: false,
		}
	},
	computed: {
	},

}

// methods: {
// 	async getSummary() {
// 		this.summaryLoading = true
// 		try {
// 			this.summaryText = await bfpSummary(this.messageText)
// 		} catch (error) {
// 			this.summaryError = true
// 			showError(t('mail', 'Summarizing thread failed.'))
// 			logger.error('Summarizing thread failed', { error })
// 		} finally {
// 			this.summaryLoading = false
// 		}
// 	},
// }
</script>
<style lang="scss" scoped>
.summary {
	background-color:var(--ck-color-base-active);
    border-radius: 8px;
    text-align: left;
    padding: 15px;
    margin: 8px;

	&__title {
		display: flex;
	}
	&__content {
    margin-top: 10px;
    margin-bottom: 10px;
	}
}
</style>
