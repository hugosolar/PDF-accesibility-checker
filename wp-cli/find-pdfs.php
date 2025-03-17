<?php //phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Find PDFs within the site.
 *
 * @package PDFFinder\CLI
 */

namespace PDFFinder\CLI;

use WP_CLI;
use WP_Query;

/**
 * Export All PDFs link within the content
 *
 * @package MSCM\CLI
 */
class FindPDFs {

	/**
	 * Header for CSV
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

	/**
	 * Redirections cache.
	 *
	 * @var array
	 */
	private $redirections = array();

	/**
	 * Exports posts containing PDFs
	 *
	 * ## OPTIONS
	 *
	 * [<output>]
	 * : The name of the output file (default: pdfs-{site}.csv)
	 *
	 * [--post_types=<post_types>]
	 * : Comma separated list of post types to search (default: post)
	 *
	 * [--network=<bool>]
	 * : Whether to search across the entire network (default: false)
	 * 
	 * [--start_date=<date>]
	 * : Filter posts from this date onwards (format: MM-DD-YYYY)
	 *
	 * ## EXAMPLES
	 *
	 *      wp pdf find-pdfs export output.csv --post_types=posts,page --network=true
	 *      wp pdf find-pdfs export output.csv --post_types=posts,page --start_date=01-01-2023
	 *
	 * @subcommand export
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @param bool  $verbose    Whether to show the output in verbose mode.
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
		$start_date = ( ! empty( $assoc_args['start_date'] ) ) ? $this->validate_date( $assoc_args['start_date'] ) : '';
		$sites      = is_multisite() ? WP_CLI::runcommand( 'site list --fields=blog_id,url,archived --format=json', $options ) : array( array( 'blog_id' => 1, 'url' => get_site_url(), 'archived' => 0 ) );

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

			if ( is_multisite() ) {
				switch_to_blog( $site['blog_id'] );
			}

			$posts_with_pdfs = $this->get_posts_with_pdfs( $post_types, $assoc_args, $site['blog_id'] );

			if ( is_multisite() ) {
				restore_current_blog();
			}

			if ( empty( $posts_with_pdfs ) ) {
				\WP_CLI::warning( 'No posts with PDFs found' );
			}

			foreach ( $posts_with_pdfs as $pdfurl ) {
				$post        = get_post( $pdfurl );
				$the_content = $post->post_content;
				$pdf_match   = array();
				$local_url   = wp_parse_url( site_url() );
				$rep_url     = $this->get_url_from_prod( $local_url['host'] );
				$permalink   = str_replace( $local_url['host'], $rep_url, get_permalink( $pdfurl ) );

				$link_regex = '/<a\s+[^>]*href=["\']([^"\']+)["\']/i';

				preg_match_all( $link_regex, $the_content, $pdf_match );
				$pdf_match = $pdf_match[1];

				if ( empty( $pdf_match[1] ) ) {
					continue;
				}

				foreach ( $pdf_match as $pdf_url ) {

					$url_to_add = $this->maybe_add_url( $pdf_url );
					if ( empty( $url_to_add ) ) {
						continue;
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
		// Check if the URL is a redirect. (using redirect.to as an example)	
		} elseif ( strpos( $pdf_url, 'redirect.to' ) !== false ) {
			$redirect_url = $this->get_redirect_url( $pdf_url );
			if ( $redirect_url && $this->is_pdf_url( $redirect_url ) ) {
				$return_url = $pdf_url;
			}
		}

		return $return_url;
	}

	/**
	 * Get the proper prod URL for the pdf if we're using local DB.
	 *
	 * @param string $host The current local URL of the site.
	 */
	public function get_url_from_prod( $host ) {
		return match ( $host ) {
			'site.test'   => 'www.site.com',
			'local.test'  => 'www.prodsite.com',
			'mysite.test' => 'mysite.com',
			default       => 'www.globalsite.com',
		};
	}

	/**
	 * Get all PDFs within wp_content.
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
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'fields'                 => 'ids',
		);

		// Add date query if start_date is provided.
		if ( ! empty( $assoc_args['start_date'] ) ) {
			$start_date = $this->validate_date( $assoc_args['start_date'] );
			if ( $start_date ) {
				$args['date_query'] = array(
					array(
						'after'     => $start_date,
						'inclusive' => true,
					),
				);
			}
		}

		$posts_with_pdfs = array();
		$query           = new WP_Query( $args );

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				$the_content = get_post_field( 'post_content', $post_id );
				
				// Find all links in the content
				$link_pattern = '/<a[^>]+href=([\'"])(.*?)\1[^>]*>/i';
				if ( preg_match_all( $link_pattern, $the_content, $matches ) ) {
					foreach ( $matches[2] as $url ) {
						// Clean the URL
						$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						$url = trim( $url );

						// Skip empty or javascript URLs
						if ( empty( $url ) || strpos( $url, 'javascript:' ) === 0 ) {
							continue;
						}

						// Check if it's a PDF URL or a redirect to PDF
						if ( $this->is_pdf_url( $url ) || $this->is_potential_pdf_redirect( $url ) ) {
							$posts_with_pdfs[] = $post_id;
							break; // Found at least one PDF in this post, no need to continue checking
						}
					}
				}
			}
		}
		return array_unique( $posts_with_pdfs );
	}

	/**
	 * Check if a URL points to a PDF.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if the URL points to a PDF, false otherwise.
	 */
	private function is_pdf_url( $url ) {
		// Check for direct PDF extension
		if ( preg_match( '/\.pdf(\?.*)?$/i', $url ) ) {
			return true;
		}

		// Check for PDF in the path segments
		$path_segments = explode( '/', parse_url( $url, PHP_URL_PATH ) );
		foreach ( $path_segments as $segment ) {
			if ( preg_match( '/\.pdf(\?.*)?$/i', $segment ) ) {
				return true;
			}
		}

		// Check for PDF in query parameters
		$query = parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $params );
			foreach ( $params as $param ) {
				if ( preg_match( '/\.pdf(\?.*)?$/i', $param ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if a URL is potentially a redirect to a PDF.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if the URL might redirect to a PDF, false otherwise.
	 */
	private function is_potential_pdf_redirect( $url ) {
		// Common redirect patterns
		$redirect_patterns = array(
			'/redirect\.to\//i',
			'/go\.redirectingat\.com/i',
			'/url\?.*url=/i', // Google redirect
			'/download\.aspx/i',
			'/download\.php/i',
			'/dl\./i',
			'/files?\//i',
			'/documents?\//i',
			'/publications?\//i',
		);

		foreach ( $redirect_patterns as $pattern ) {
			if ( preg_match( $pattern, $url ) ) {
				return true;
			}
		}

		return false;
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

		WP_CLI::line( 'Checking redirect URL: ' . $url );
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

	/**
	 * Validate and format the date string.
	 *
	 * @param string $date Date string in MM-DD-YYYY format.
	 * @return string|bool Formatted date string or false if invalid.
	 */
	private function validate_date( $date ) {
		$d = \DateTime::createFromFormat( 'm-d-Y', $date );
		if ( $d && $d->format( 'm-d-Y' ) === $date ) {
			return $d->format( 'Y-m-d' );
		}
		WP_CLI::error( 'Invalid date format. Please use MM-DD-YYYY format.' );
		return false;
	}

}

try {
	WP_CLI::add_command( 'pdf find-pdfs', __NAMESPACE__ . '\\FindPDFs' );
 } catch ( \Exception $e ) {
	die( $e->getMessage() ); // phpcs:ignore
}
