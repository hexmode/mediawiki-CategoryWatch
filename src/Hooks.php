<?php
/*
 * Copyright (C) 2016  Mark A. Hershberger
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace CategoryWatch;

use MWException;
use SpecialWatchlist;
use User;
use RecentChange;
use RequestContext;

class Hooks {
	/**
	 * Add a new option to the special page.
	 *
	 * @param SpecialWatchlist $specialPage
	 * @param Array $customOpts List of added options
	 */
	static public function onSpecialWatchlistFilters(
		SpecialWatchlist $specialPage, &$customOpts
	) {
		$customOpts['hidecategory'] = [
			'default' => $specialPage->getUser()->getBoolOption(
				'watchlisthidecategory'
			),
			'msg' => 'categorywatch-rchidecategory'
		];
	}

	/**
	 * Add a new user preference.
	 *
	 * @param User $user the user being modified.
	 * @param Array $preference the preference array
	 */
	static public function onGetPreferences( User $user, &$preference ) {
		$preference['watchlisthidecategory'] = [
			'type' => 'toggle',
			'section' => 'watchlist/advancedwatchlist',
			'label-message' => 'tog-watchlisthidecategory',
		];
	}

	/**
	 * Change query if showing categories is set.
	 *
	 * @param Array $conds Where conditions for query
	 * @param Array $tables Tables to use in query
	 * @param Array $join_conds Join conditions
	 * @param Array $fields Fields to select in the query
	 * @param Array $values Option values for special pages
	 * @throws MWException If core changes how it makes the query, and this
	 *				doesn't work.
	 */
	static public function onSpecialWatchlistQuery(
		&$conds, &$tables, &$join_conds, &$fields, $values
	) {
		if ( !$values['hidecategory'] ) {
			// This is a tad fragile
			if ( isset( $join_conds['watchlist'] )
				&& $join_conds['watchlist'][1][1] === 'wl_title=rc_title'
			) {
			//throw new MWException( __METHOD__ . "Haven't written this yet." );
			} else {
			//	throw new MWException( __METHOD__ .
			//						   " Could not modify Watchlist query." );
			}
		}
	}

	static public function onRecentChange_save( RecentChange $change ) {
		# If $title is in one of our watched categories, send an email
		$editor = $change->getPerformer();
		$title = $change->getTitle();
		$config = RequestContext::getMain()->getConfig();

		wfDebugLog( __METHOD__, var_export( ['EnotifWatchlist' => $config->get( 'EnotifWatchlist' ),
											 'UseEnotif' => $config->get( "UseEnotif" ),
											 'ShowUpdatedMarker' => $config->get( "ShowUpdatedMarker" )
		], true ) );
		# E-mail notifications
		if ( $config->get( 'EnotifWatchlist' ) || $config->get( "UseEnotif" )
			 || $config->get( "ShowUpdatedMarker" ) ) {
			# TitleArray of categories for page
			$categories = WikiPage::factory( $title )->getCategories();

			$dbr = wfGetDB( DB_SLAVE );
			$watchers = [];
			# Get list of watchers for those categories
			foreach ( $categories as $cat ) {
				$res = $db->select( 'watchlist',
									[ 'wl_user' ],
									[ 'wl_namespace' => $cat->getNamespace(),
									  'wl_title' => $cat->getDBkey() ],
									__METHOD__
				);
				foreach( $res as $row ) {
					$watchers[$row->wl_user] = true;
				}
			}
			var_dump($watchers);exit;
			# Add them to the list of watchers that will be notified (param for EnotifNotifyJob)
			// Unlike the caller, allow notification email about categorization changes
			if ( Hooks::run( 'AbortEmailNotification', [ $editor, $title, $change ] ) ) {
				JobQueueGroup::singleton()->lazyPush( new EnotifNotifyJob(
					$title,
					[
						'editor' => $editor->getName(),
						'editorID' => $editor->getId(),
						'timestamp' => $change->mAttribs['rc_timestamp'],
						'summary' => $change->mAttribs['rc_comment'],
						'minorEdit' => $change->mAttribs['rc_minor'],
						'oldid' => $change->mAttribs['rc_last_oldid'],
						'watchers' => array_keys( $watchers ),
						'pageStatus' => $change->mExtra['pageStatus']
					]
				) );
			}
		}
	}
}
