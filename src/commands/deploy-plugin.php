<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use phpseclib\Net\SFTP;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;

class Deploy_Plugin extends Command {
	protected static $defaultName = 'deploy-plugin';

	protected function configure() {
		$this
		->setDescription( "Deploys plugin and creates world peace" );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper;

		$pressable_sites = $api_helper->call_pressable_api(
			"sites/",
			'GET',
			array()
		);

		if ( empty( $pressable_sites->data ) ) {
			$output->writeln( "<error>Failed to retrieve Pressable sites. Aborting!</error>" );
			exit;
		}

		$pressable_site = null;

		/***
		stdClass Object
		(
		[id] => 1001637
		[created] => 2016-07-25T12:29:23.000Z
		[accountId] => 1002814
		[clonedFromId] =>
		[collaboratorsCount] => 0
		[displayName] => concierge
		[domainsCount] => 0
		[ecommerce] =>
		[favorite] =>
		[ipAddress] => 199.16.172.5
		[ipAddressOne] => 199.16.172.5
		[ipAddressTwo] => 199.16.173.189
		[name] => concierge
		[state] => live
		[url] => concierge.mystagingwebsite.com
		[staging] =>
		[sftpDomain] => sftp.pressable.com
		)
		***/

		foreach( $pressable_sites->data as $pressable_site ) {
			$output->writeln( "Accessing {$pressable_site->url}" );


			$output->writeln( "<comment>Adding temporary bot collaborator to {$pressable_site->url}.</comment>" );

			$add_collaborator_request = $api_helper->call_pressable_api(
				"sites/{$pressable_site->id}/collaborators",
				'POST',
				array(
					'siteId' => $pressable_site->id,
					'email' => PRESSABLE_BOT_COLLABORATOR_EMAIL,
				)
			);

			if ( empty( $add_collaborator_request->data->accountId ) ) {
				$output->writeln( "<error>Failed to create a temporary bot collaborator. Aborting!</error>" );
				exit;
			}

			$output->writeln( "<comment>Getting bot collaborator SFTP credentials.</comment>" );

			// Grab SFTP connection info from Pressable bot collaborator.
			$ftp_data = $api_helper->call_pressable_api( "sites/{$pressable_site->id}/ftp", 'GET', array() );

			if( empty( $ftp_data->data ) ) {
				$output->writeln( "<error>Failed to retrieve FTP users. Aborting!</error>" );
				exit;
			}

			$ftp_user_id = max( array_column( $ftp_data->data, 'id' ) );

			$ftp_config = array();

			foreach( $ftp_data->data as $ftp_user ) {
				if( $ftp_user->id === $ftp_user_id ) { // We found the bot collaborator we created, grab the info.
					$ftp_config['sftp_username'] = $ftp_user->username;
					$ftp_config['sftp_hostname'] = $ftp_user->sftpDomain;

					$password_reset = $api_helper->call_pressable_api( "sites/{$pressable_site->id}/ftp/password/{$ftp_user->username}", 'POST', array() );
					if( ! empty( $password_reset->data ) ) {
						$ftp_config['sftp_password'] = $password_reset->data;
					} else {
						$output->writeln( "<error>Failed to retrieve password for temporary bot collaborator. Aborting!</error>" );
						exit;
					}
					break;
				}
			}

			$output->writeln( "<comment>Opening SFTP connection.</comment>" );

			// Time to connect to the server.
			$sftp_connection = new SFTP( $ftp_config['sftp_hostname'] );

			if ( ! $sftp_connection->login( $ftp_config['sftp_username'], $ftp_config['sftp_password'] ) ) {
				$output->writeln( "<error>Failed to connect to the server via SFTP. Aborting!</error>" );
				exit;
			}

			$output->writeln( "<comment>Uploading plugin.</comment>" );

			$php_errors = $sftp_connection->put( '/htdocs/wp-content/plugins/plugin-autoupdate-filter/', TEAM51_CLI_ROOT_DIR . "/scaffold/templates/plugin-autoupdate-filter/", SFTP::SOURCE_LOCAL_FILE );

			$output->writeln( "<comment>Removing bot collaborator.</comment>" );

			$delete_contributor_request = $api_helper->call_pressable_api(
				"sites/{$pressable_site->id}/collaborators/{$add_collaborator_request->data->id}",
				'DELETE',
				array()
			);

			exit; // exits after first one for testing
		} // end site foreach
	}
}
