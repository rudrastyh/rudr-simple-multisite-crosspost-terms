<?php
/*
 * Plugin name: Simple Multisite Crossposting – Terms
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost terms with all the data along with the post.
 * Version: 1.7
 * Network: true
 */

add_action( 'rudr_crosspost_custom_terms_processing', function( $terms ) {

	$allow_updates = apply_filters( 'rudr_crosspost_terms_allow_updates', true );

	// we have $terms here just in the format of
	// Array( 'taxonomy' => Array( 'term1', 'term2', ... )

	// let's double check if it is not empty or not an array
	if( ! $terms || ! is_array( $terms ) ) {
		return $terms; // kind of do nothing
	}

	$blog_id = get_current_blog_id();

	//let's start a loop!
	$new_terms = array();

	foreach( $terms as $taxonomy => $term_slugs ) {

		// we have to create a new array of terms that are needed to be created
		// if there no specific taxonomy, add it to the array
		if( ! isset( $new_terms[ $taxonomy ] ) ) {
			$new_terms[ $taxonomy ] = array();
		}
		//ok if term doesn't exist on a new blog, let's add it to an array
		foreach( $term_slugs as $term_slug ) {
			if( $allow_updates || ! term_exists( $term_slug, $taxonomy ) ) {
				$new_terms[$taxonomy][] = $term_slug;
			}
		}

	}

	foreach( $new_terms as $taxonomy => $term_slugs ) {

		foreach( $term_slugs as $term_slug ) {
			restore_current_blog();

			// switching back to get original term data
			$prev_site_term = get_term_by( 'slug', $term_slug, $taxonomy );

			$data = array(
				'name' => $prev_site_term->name,
				'slug' => $prev_site_term->slug,
				'description' => $prev_site_term->description,
				'term_meta' => get_term_meta( $prev_site_term->term_id ), // Array ( [key] => Array ( [0] => val )
				'parent' => 0,
			);

			// prepare parent term
			if(
				$prev_site_term->parent
				&& ( $parent_term = get_term_by( 'id', $prev_site_term->parent, $taxonomy ) )
			) {
				$data[ 'parent' ] = $parent_term->slug;
			}

			// prepare thumbnails for WooCommerce
			if( 'product_cat' === $taxonomy && ! empty( $data[ 'term_meta' ][ 'thumbnail_id' ][0] ) ) {
				$data[ 'product_cat_thumbnail_data' ] = Rudr_Simple_Multisite_Crosspost::prepare_attachment_data( $data[ 'term_meta' ][ 'thumbnail_id' ][0] );
				unset( $data[ 'term_meta' ][ 'thumbnail_id' ] );
			}

			switch_to_blog( $blog_id );

			// replace parent term slug with ID
			if( $data[ 'parent' ] && ( $parent_term = get_term_by( 'slug', $data[ 'parent' ], $taxonomy ) ) ) {
				$data[ 'parent' ] = $parent_term->term_id;
			}

			if( $allow_updates && $existing_term = term_exists( $prev_site_term->slug, $taxonomy ) ) {
				$term = wp_update_term(
					$existing_term[ 'term_id' ],
					$taxonomy,
					array(
						'name' => $data[ 'name' ],
						'slug' => $data[ 'slug' ],
						'description' => $data[ 'description' ],
						'parent' => $data[ 'parent' ],
					)
				);

			} else {
				$term = wp_insert_term(
					$data[ 'name' ],
					$taxonomy,
					array(
						'description' => $data[ 'description' ],
						'slug' => $data[ 'slug' ],
						'parent' => $data[ 'parent' ],
					)
				);
			}

			if( is_wp_error( $term ) ) {
				continue;
			}

			// just updating meta data
			foreach ( $data[ 'term_meta' ] as $meta_key => $meta_values ) {

				// clean up first
				if( $allow_updates ) {
					delete_term_meta( $term[ 'term_id' ], $meta_key );
				}
				foreach ( $meta_values as $meta_value ) {
					$meta_value = apply_filters( 'rudr_pre_crosspost_termmeta', $meta_value, $meta_key, $prev_site_term->term_id );
					add_term_meta( $term[ 'term_id' ], $meta_key, maybe_unserialize( $meta_value ) );
				}

			}

			// WooCommerce thumbnail
			if( isset( $data[ 'product_cat_thumbnail_data' ] ) && $data[ 'product_cat_thumbnail_data' ] ) {
				$product_cat_thumbnail = Rudr_Simple_Multisite_Crosspost::maybe_copy_image( $data[ 'product_cat_thumbnail_data' ] );
				if( isset( $product_cat_thumbnail[ 'id' ] ) ) {
					if( $allow_updates ) {
						delete_term_meta( $term[ 'term_id' ], 'thumbnail_id' );
					}
					add_term_meta( $term[ 'term_id' ], 'thumbnail_id', $product_cat_thumbnail[ 'id' ] );
				}
			}


		}

	}


} );
