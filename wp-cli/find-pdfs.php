<?php //phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Find PDFs within the site.
 *
 * @package MSX\CLI
 */

namespace MSX\CLI;

use WP_CLI;
use WP_Query;

/**
 * Export All PDFs link within the content of the site.
 *
 * @package MSCM\CLI
 */
class FindPDFs {

	/**
	 * header for CSV
	 *
	 * @var array
	 */
	private $csv_headers = array(
		// General Info.
		'Post ID',
		'PDF URL',
		'Post Date',
		'Post Title',
		'Post URL',
	);

	private $redirections = array();

	/**
	 * Exports posts containing PDFs
	 *
	 *
	 * ## EXAMPLES
	 *
	 *      wp mscm find-pdfs export output.csv --post_types=posts,page --network=true
	 *
	 * @subcommand export
	 *
	 * @param array $args 	Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @param bool  $verbose 	Whether to show the output in verbose mode.
	 */
	public function export( $args, $assoc_args ) {
		// Fetch our sites.
		$options = array(
			'return'     => true,   // Return 'STDOUT'; use 'all' for full object.
			'parse'      => 'json', // Parse captured STDOUT to JSON array.
			'launch'     => false,  // Reuse the current process.
			'exit_error' => true,   // Halt script execution on error.
		);

		$post_types = ( ! empty( $assoc_args['post_types'] ) ) ? explode( ',', $assoc_args['post_types'] ) : array( 'post' );
		$network    = ( ! empty( $assoc_args['network'] ) ) ? true : false;
		$sites      = WP_CLI::runcommand( 'site list --fields=blog_id,url,archived --format=json', $options );

		WP_CLI::line( '=== Get PDFs from sites ===' );

		foreach ( $sites as $site ) {
			// Skip archived sites.
			if ( intval( $site['archived'] ) ) {
				WP_CLI::warning( 'Site ' . $site['url'] . ' archived, skipping indexing.' );
				continue;
			}

			WP_CLI::line( 'Processing site: ' . $site['url'] );

			$url_id    = sanitize_title_with_dashes( $site['url'] );
			$filename  = ( ! empty( $args[0] ) ) ? $args[0] : 'pdfs-' . $url_id . '.csv';
			$delimiter = ',';
			$found     = 0;

			$file_handler = fopen( $filename, 'w+' );

			if ( ! $file_handler ) {
				\WP_CLI::error( 'Impossible to create the file' );
			}

			$headers = $this->csv_headers;
			fputcsv( $file_handler, $headers, $delimiter );

			switch_to_blog( $site['blog_id'] );
			$posts_with_pdfs = $this->get_posts_with_pdfs( $post_types, $assoc_args, $site['blog_id'] );
			restore_current_blog();

			if ( empty( $posts_with_pdfs ) ) {
				\WP_CLI::error( 'No posts with PDFs found' );
			}

			foreach ( $posts_with_pdfs as $pdfurl ) {
				$post        = get_post( $pdfurl );
				$the_content = $post->post_content;
				$pdf_match   = array();
				$local_url   = wp_parse_url( site_url() );
				$rep_url     = $this->get_url_from_prod( $local_url['host'] );
				$permalink   = str_replace( $local_url['host'], $rep_url, get_permalink( $pdfurl ) );

				$link_regex = '/https?:\/\/[^\s"]+/';

				preg_match_all( $link_regex, $the_content, $pdf_match );
				$pdf_match = $pdf_match[0];

				if ( empty( $pdf_match[0] ) ) {
					break;
				}

				foreach ( $pdf_match as $pdf_url ) {

					$url_to_add = $this->maybe_add_url( $pdf_url );
					if ( empty( $url_to_add ) ) {
						break;
					}

					$post_data = array(
						$post->ID,
						$url_to_add,
						$post->post_date,
						$post->post_title,
						$permalink,
					);

					fputcsv( $file_handler, $post_data, $delimiter );
					++$found;
				}
			}
			if ( ! $network ) {
				break;
			}
		}

		fclose( $file_handler );

		WP_CLI::success(
			sprintf(
				'%d posts with PDFs have been exported to %s',
				absint( count( $posts_with_pdfs ) ),
				$filename
			)
		);
	}

	/**
	 * Check if the URL is a PDF or a redirect to a PDF.
	 *
	 * @param mixed $pdf_url The URL to check.
	 * 
	 * @return mixed
	 */
	private function maybe_add_url( $pdf_url ) {
		$return_url = false;
		if ( $this->is_pdf_url( $pdf_url ) ) {
			$return_url = $pdf_url;
		} elseif ( strpos( $pdf_url, 'aka.ms' ) !== false ) {
			$redirect_url = $this->get_redirect_url( $pdf_url );
			if ( $redirect_url && $this->is_pdf_url( $redirect_url ) ) {
				$return_url = $pdf_url;
			}
		}

		return $return_url;
	}

	/**
	 * Get the proper prod URL for the pdf.
	 *
	 * @param string $host The current local URL of the site.
	 */
	public function get_url_from_prod( $host ) {
		return match ( $host ) {
			'azure.test'      => 'azure.microsoft.com',
			'opensource.test' => 'opensource.microsoft.com',
			'quantum.test'    => 'azure.microsoft.com',
			default           => 'www.microsoft.com',
		};
	}

	/**
	 * Get all posts with videoplayer/embed block in wp_content.
	 *
	 * @param array $post_types The post types to search for.
	 * @param array $assoc_args The associative arguments.
	 * @param int   $blog_id The blog ID to search for.
	 *
	 * @return array
	 */
	public function get_posts_with_pdfs( $post_types, $assoc_args, $blog_id = 1 ) {

		$args = array(
			'post_type'              => $post_types,
			'post_satus'             => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'fields'                 => 'ids',
		);

		$posts_with_pdfs = array();
		$is_locale       = ( isset( $assoc_args['locale'] ) ) ? true : false;
		$network         = ( isset( $assoc_args['network'] ) ) ? true : false;
		$query           = new WP_Query( $args );

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				$the_content = get_post_field( 'post_content', $post_id );
				$pdf_pattern = '/<a\s+[^>]*href=["\']([^"\']+\.pdf)["\']/i';
				$pattern_aka = '/<a\s+[^>]*href=["\'](https?:\/\/aka\.ms\/[^"\']+)["\']/i';

				if ( preg_match( $pdf_pattern, $the_content ) || preg_match( $pattern_aka, $the_content ) ) {
					$posts_with_pdfs[] = $post_id;
				}
			}
		}
		return $posts_with_pdfs;
	}

	/**
	 * Check if a URL points to a PDF.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if the URL points to a PDF, false otherwise.
	 */
	private function is_pdf_url( $url ) {
		return preg_match( '/\.pdf$/i', $url );
	}

	/**
	 * Get the final redirect URL for a given URL.
	 *
	 * @param string $url The URL to check.
	 * @return string|false The final redirect URL, or false if no redirect.
	 */
	private function get_redirect_url( $url ) {
		if ( ! empty( $this->redirections[ $url ] ) ) {
			WP_CLI::line( 'Found cached redirection from ' . $url . ' to: ' . $this->redirections[ $url ] );
			return $this->redirections[ $url ];
		}

		WP_CLI::line( 'Checking aka.ms URL: ' . $url );
		$response = get_headers( $url, 1 );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( isset( $response['Location'] ) ) {
			if ( is_array( $response['Location'] ) ) {
				foreach ( $response['Location'] as $location ) {
					if ( filter_var( $location, FILTER_VALIDATE_URL ) ) {
						$redirect_url = $location;
					}
				}
			} elseif ( filter_var( $response['Location'], FILTER_VALIDATE_URL ) ) {
				$redirect_url = $response['Location'];
			}

			WP_CLI::line( 'Found redirection to: ' . $redirect_url );
			$this->redirections[ $url ] = $redirect_url;

			return $redirect_url;
		}
		
		return false;
	}

}

try {
	WP_CLI::add_command( 'mscm find-pdfs', __NAMESPACE__ . '\\FindPDFs' );
 } catch ( \Exception $e ) {
	die( $e->getMessage() ); // phpcs:ignore
}
