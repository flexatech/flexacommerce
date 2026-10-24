<?php

// WooCommerce product shapes for the phase 2 mapping spike (doc 03 §3.9).
// "real" records were exported read-only from a local WooCommerce 11.0.1
// store on 2026-10-24 (units kg and cm, USD); "synthetic" records are
// hand-written in the same meta format for shapes that store did not have.

return array (
  'taxonomies' => 
  array (
    'pa_color' => 
    array (
      'label' => 'Color',
      'terms' => 
      array (
        'red' => 'Red',
        'blue' => 'Blue',
      ),
    ),
    'pa_pattern' => 
    array (
      'label' => 'Pattern',
      'terms' => 
      array (
        'pant' => 'Pant',
        'shirt' => 'Shirt',
      ),
    ),
    'pa_size' => 
    array (
      'label' => 'Size',
      'terms' => 
      array (
        'large' => 'Large',
        'medium' => 'Medium',
      ),
    ),
    'pa_made-in' => 
    array (
      'label' => 'Made in',
      'terms' => 
      array (
        'usa' => 'USA',
        'uk' => 'UK',
      ),
    ),
    'pa_print-size' => 
    array (
      'label' => 'Size',
      'terms' => 
      array (
        'a4' => 'A4 · 21 × 30 cm',
        'a3' => 'A3 · 30 × 40 cm',
        'a2' => 'A2 · 50 × 70 cm',
      ),
    ),
    'pa_frame' => 
    array (
      'label' => 'Frame',
      'terms' => 
      array (
        'oak' => 'Oak',
        'black' => 'Black',
        'white' => 'White',
        'unframed' => 'Unframed',
      ),
    ),
    'pa_paper' => 
    array (
      'label' => 'Paper',
      'terms' => 
      array (
        'natural-white' => 'Natural white',
        'warm-cream' => 'Warm cream',
        'soft-grey' => 'Soft grey',
      ),
    ),
  ),
  'products' => 
  array (
    0 => 
    array (
      'source' => 'real',
      'id' => 60,
      'title' => 'Example',
      'status' => 'draft',
      'type' => 'variable',
      'visibility_terms' => 
      array (
      ),
      'meta' => 
      array (
        '_backorders' => 'no',
        '_manage_stock' => 'no',
        '_stock' => NULL,
        '_stock_status' => 'instock',
        '_tax_class' => '',
      ),
      'attributes' => 
      array (
        'size' => 
        array (
          'name' => 'Size',
          'value' => 'Small | Large',
          'position' => 0,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 0,
        ),
      ),
      'terms' => 
      array (
      ),
      'variations' => 
      array (
        0 => 
        array (
          'id' => 61,
          'status' => 'publish',
          'menu_order' => 1,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '100',
            '_sale_price' => '80',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_thumbnail_id' => '0',
            '_variation_description' => '',
            'attribute_size' => 'Small',
          ),
        ),
        1 => 
        array (
          'id' => 62,
          'status' => 'publish',
          'menu_order' => 2,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '200',
            '_sale_price' => '160',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_thumbnail_id' => '0',
            '_variation_description' => '',
            'attribute_size' => 'Large',
          ),
        ),
      ),
    ),
    1 => 
    array (
      'source' => 'real',
      'id' => 703,
      'title' => 'Variable product',
      'status' => 'draft',
      'type' => 'variable',
      'visibility_terms' => 
      array (
      ),
      'meta' => 
      array (
        '_backorders' => 'no',
        '_manage_stock' => 'no',
        '_stock' => NULL,
        '_stock_status' => 'instock',
        '_tax_class' => '',
        '_thumbnail_id' => '708',
      ),
      'attributes' => 
      array (
        'pa_color' => 
        array (
          'name' => 'pa_color',
          'value' => '',
          'position' => 0,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
        'pa_made-in' => 
        array (
          'name' => 'pa_made-in',
          'value' => '',
          'position' => 2,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
        'pa_pattern' => 
        array (
          'name' => 'pa_pattern',
          'value' => '',
          'position' => 3,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
        'pa_size' => 
        array (
          'name' => 'pa_size',
          'value' => '',
          'position' => 4,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
      ),
      'terms' => 
      array (
        'pa_color' => 
        array (
          0 => 'red',
          1 => 'blue',
        ),
        'pa_made-in' => 
        array (
          0 => 'usa',
          1 => 'uk',
        ),
        'pa_pattern' => 
        array (
          0 => 'pant',
          1 => 'shirt',
        ),
        'pa_size' => 
        array (
          0 => 'large',
          1 => 'medium',
        ),
      ),
      'variations' => 
      array (
        0 => 
        array (
          'id' => 704,
          'status' => 'publish',
          'menu_order' => 1,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '100',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_thumbnail_id' => '410',
            '_variation_description' => '',
            'attribute_pa_color' => 'blue',
            'attribute_pa_made-in' => '',
            'attribute_pa_pattern' => '',
            'attribute_pa_size' => '',
          ),
        ),
        1 => 
        array (
          'id' => 705,
          'status' => 'publish',
          'menu_order' => 2,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '100',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_thumbnail_id' => '439',
            '_variation_description' => '',
            'attribute_pa_color' => 'red',
            'attribute_pa_made-in' => '',
            'attribute_pa_pattern' => '',
            'attribute_pa_size' => '',
          ),
        ),
      ),
    ),
    2 => 
    array (
      'source' => 'real',
      'id' => 1412,
      'title' => 'Coastal Horizon Print',
      'status' => 'publish',
      'type' => 'variable',
      'visibility_terms' => 
      array (
        0 => 'featured',
      ),
      'meta' => 
      array (
        '_backorders' => 'no',
        '_manage_stock' => 'no',
        '_sku' => 'NB-COAST',
        '_stock' => NULL,
        '_stock_status' => 'instock',
        '_tax_class' => '',
        '_thumbnail_id' => '1388',
      ),
      'attributes' => 
      array (
        'pa_print-size' => 
        array (
          'name' => 'pa_print-size',
          'value' => '',
          'position' => 0,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
        'pa_frame' => 
        array (
          'name' => 'pa_frame',
          'value' => '',
          'position' => 0,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
        'pa_paper' => 
        array (
          'name' => 'pa_paper',
          'value' => '',
          'position' => 0,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
      ),
      'terms' => 
      array (
        'pa_frame' => 
        array (
          0 => 'oak',
          1 => 'black',
          2 => 'white',
          3 => 'unframed',
        ),
        'pa_paper' => 
        array (
          0 => 'natural-white',
          1 => 'warm-cream',
        ),
        'pa_print-size' => 
        array (
          0 => 'a4',
          1 => 'a3',
          2 => 'a2',
        ),
      ),
      'variations' => 
      array (
        0 => 
        array (
          'id' => 1413,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '38',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'unframed',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a4',
          ),
        ),
        1 => 
        array (
          'id' => 1414,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '42',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'unframed',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a4',
          ),
        ),
        2 => 
        array (
          'id' => 1415,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '83',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'oak',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a4',
          ),
        ),
        3 => 
        array (
          'id' => 1416,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '87',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'oak',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a4',
          ),
        ),
        4 => 
        array (
          'id' => 1417,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '83',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'black',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a4',
          ),
        ),
        5 => 
        array (
          'id' => 1418,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '87',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'black',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a4',
          ),
        ),
        6 => 
        array (
          'id' => 1419,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '80',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'white',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a4',
          ),
        ),
        7 => 
        array (
          'id' => 1420,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '84',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'white',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a4',
          ),
        ),
        8 => 
        array (
          'id' => 1421,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '52',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'unframed',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a3',
          ),
        ),
        9 => 
        array (
          'id' => 1422,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '56',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'unframed',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a3',
          ),
        ),
        10 => 
        array (
          'id' => 1423,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '97',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'oak',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a3',
          ),
        ),
        11 => 
        array (
          'id' => 1424,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '101',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'oak',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a3',
          ),
        ),
        12 => 
        array (
          'id' => 1425,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '97',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'black',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a3',
          ),
        ),
        13 => 
        array (
          'id' => 1426,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '101',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'black',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a3',
          ),
        ),
        14 => 
        array (
          'id' => 1427,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '94',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'white',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a3',
          ),
        ),
        15 => 
        array (
          'id' => 1428,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '98',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'white',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a3',
          ),
        ),
        16 => 
        array (
          'id' => 1429,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '78',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'unframed',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a2',
          ),
        ),
        17 => 
        array (
          'id' => 1430,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '82',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'unframed',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a2',
          ),
        ),
        18 => 
        array (
          'id' => 1431,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '123',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_thumbnail_id' => '1387',
            '_variation_description' => '',
            'attribute_pa_frame' => 'oak',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a2',
          ),
        ),
        19 => 
        array (
          'id' => 1432,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '127',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_thumbnail_id' => '1387',
            '_variation_description' => '',
            'attribute_pa_frame' => 'oak',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a2',
          ),
        ),
        20 => 
        array (
          'id' => 1433,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '123',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_thumbnail_id' => '1385',
            '_variation_description' => '',
            'attribute_pa_frame' => 'black',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a2',
          ),
        ),
        21 => 
        array (
          'id' => 1434,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '127',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_thumbnail_id' => '1385',
            '_variation_description' => '',
            'attribute_pa_frame' => 'black',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a2',
          ),
        ),
        22 => 
        array (
          'id' => 1435,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '120',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'white',
            'attribute_pa_paper' => 'natural-white',
            'attribute_pa_print-size' => 'a2',
          ),
        ),
        23 => 
        array (
          'id' => 1436,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            '_backorders' => 'no',
            '_manage_stock' => 'no',
            '_regular_price' => '124',
            '_stock' => NULL,
            '_stock_status' => 'instock',
            '_tax_class' => 'parent',
            '_variation_description' => '',
            'attribute_pa_frame' => 'white',
            'attribute_pa_paper' => 'warm-cream',
            'attribute_pa_print-size' => 'a2',
          ),
        ),
      ),
    ),
    3 => 
    array (
      'source' => 'real',
      'id' => 1403,
      'title' => 'Terrazzo Print',
      'status' => 'publish',
      'type' => 'simple',
      'visibility_terms' => 
      array (
      ),
      'meta' => 
      array (
        '_backorders' => 'no',
        '_manage_stock' => 'no',
        '_regular_price' => '38',
        '_sale_price' => '29',
        '_sku' => 'NB-TERR',
        '_stock' => NULL,
        '_stock_status' => 'instock',
        '_tax_class' => '',
        '_thumbnail_id' => '1398',
      ),
      'attributes' => 
      array (
      ),
      'terms' => 
      array (
      ),
      'variations' => 
      array (
      ),
    ),
    4 => 
    array (
      'source' => 'real',
      'id' => 1406,
      'title' => 'Eclipse Print',
      'status' => 'publish',
      'type' => 'simple',
      'visibility_terms' => 
      array (
      ),
      'meta' => 
      array (
        '_backorders' => 'no',
        '_manage_stock' => 'yes',
        '_regular_price' => '44',
        '_sku' => 'NB-ECLIPSE',
        '_stock' => '2',
        '_stock_status' => 'instock',
        '_tax_class' => '',
        '_thumbnail_id' => '1390',
      ),
      'attributes' => 
      array (
      ),
      'terms' => 
      array (
      ),
      'variations' => 
      array (
      ),
    ),
    5 => 
    array (
      'source' => 'real',
      'id' => 203,
      'title' => 'Demo Wireless Headphones',
      'status' => 'draft',
      'type' => 'simple',
      'visibility_terms' => 
      array (
      ),
      'meta' => 
      array (
        '_backorders' => 'no',
        '_manage_stock' => 'no',
        '_regular_price' => '48.99',
        '_stock' => NULL,
        '_stock_status' => 'instock',
        '_tax_class' => '',
      ),
      'attributes' => 
      array (
      ),
      'terms' => 
      array (
      ),
      'variations' => 
      array (
      ),
    ),
    6 => 
    array (
      'source' => 'synthetic',
      'id' => 9001,
      'title' => 'Canvas Tote',
      'status' => 'publish',
      'type' => 'variable',
      'visibility_terms' => 
      array (
      ),
      'meta' => 
      array (
        '_sku' => 'TOTE',
        '_manage_stock' => 'yes',
        '_stock' => '12',
        '_backorders' => 'no',
        '_stock_status' => 'instock',
        '_weight' => '0.35',
        '_length' => '40',
        '_width' => '35',
        '_height' => '10.5',
      ),
      'attributes' => 
      array (
        'color' => 
        array (
          'name' => 'Color',
          'value' => 'Natural | Black',
          'position' => 0,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 0,
        ),
      ),
      'terms' => 
      array (
      ),
      'variations' => 
      array (
        0 => 
        array (
          'id' => 9002,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            'attribute_color' => 'Natural',
            '_regular_price' => '25',
            '_manage_stock' => 'no',
            '_stock_status' => 'instock',
            '_weight' => '',
          ),
        ),
        1 => 
        array (
          'id' => 9003,
          'status' => 'publish',
          'menu_order' => 1,
          'meta' => 
          array (
            'attribute_color' => 'Black',
            '_sku' => 'TOTE-BLK',
            '_regular_price' => '25',
            '_manage_stock' => 'yes',
            '_stock' => '3',
            '_backorders' => 'notify',
            '_stock_status' => 'instock',
            '_weight' => '0.4',
            '_variation_description' => 'Black cotton canvas.',
          ),
        ),
      ),
    ),
    7 => 
    array (
      'source' => 'synthetic',
      'id' => 9010,
      'title' => 'Linen Shirt',
      'status' => 'publish',
      'type' => 'simple',
      'visibility_terms' => 
      array (
        0 => 'exclude-from-search',
      ),
      'meta' => 
      array (
        '_sku' => 'SHIRT-LIN',
        '_regular_price' => '49.00',
        '_sale_price' => '39.5',
        '_sale_price_dates_from' => '1767225600',
        '_sale_price_dates_to' => '1767916799',
        '_manage_stock' => 'yes',
        '_stock' => '0',
        '_backorders' => 'notify',
        '_low_stock_amount' => '3',
        '_stock_status' => 'onbackorder',
        '_weight' => '0.2',
        '_length' => '',
      ),
      'attributes' => 
      array (
      ),
      'terms' => 
      array (
      ),
      'variations' => 
      array (
      ),
    ),
    8 => 
    array (
      'source' => 'synthetic',
      'id' => 9020,
      'title' => 'Gift Wrap',
      'status' => 'publish',
      'type' => 'simple',
      'visibility_terms' => 
      array (
        0 => 'exclude-from-catalog',
        1 => 'exclude-from-search',
      ),
      'meta' => 
      array (
        '_sku' => ' WRAP-01 ',
        '_regular_price' => '',
        '_manage_stock' => 'yes',
        '_stock' => '5.000000',
        '_backorders' => 'no',
        '_stock_status' => 'instock',
      ),
      'attributes' => 
      array (
      ),
      'terms' => 
      array (
      ),
      'variations' => 
      array (
      ),
    ),
    9 => 
    array (
      'source' => 'synthetic',
      'id' => 9030,
      'title' => 'Hoodie',
      'status' => 'publish',
      'type' => 'variable',
      'visibility_terms' => 
      array (
      ),
      'meta' => 
      array (
        '_manage_stock' => 'no',
        '_stock_status' => 'instock',
      ),
      'attributes' => 
      array (
        'pa_color' => 
        array (
          'name' => 'pa_color',
          'value' => '',
          'position' => 0,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
        'pa_size' => 
        array (
          'name' => 'pa_size',
          'value' => '',
          'position' => 1,
          'is_visible' => 1,
          'is_variation' => 1,
          'is_taxonomy' => 1,
        ),
      ),
      'terms' => 
      array (
        'pa_color' => 
        array (
          0 => 'red',
          1 => 'blue',
        ),
        'pa_size' => 
        array (
          0 => 'large',
          1 => 'medium',
        ),
      ),
      'variations' => 
      array (
        0 => 
        array (
          'id' => 9031,
          'status' => 'publish',
          'menu_order' => 0,
          'meta' => 
          array (
            'attribute_pa_color' => 'red',
            'attribute_pa_size' => '',
            '_regular_price' => '30',
            '_sale_price' => '30',
          ),
        ),
        1 => 
        array (
          'id' => 9032,
          'status' => 'publish',
          'menu_order' => 1,
          'meta' => 
          array (
            'attribute_pa_color' => 'red',
            'attribute_pa_size' => '',
            '_regular_price' => '32',
          ),
        ),
        2 => 
        array (
          'id' => 9033,
          'status' => 'private',
          'menu_order' => 2,
          'meta' => 
          array (
            'attribute_pa_color' => 'blue',
            'attribute_pa_size' => 'medium',
            '_regular_price' => '30',
          ),
        ),
        3 => 
        array (
          'id' => 9034,
          'status' => 'publish',
          'menu_order' => 3,
          'meta' => 
          array (
            'attribute_pa_color' => 'green',
            'attribute_pa_size' => 'large',
            '_regular_price' => '30',
          ),
        ),
      ),
    ),
  ),
);
