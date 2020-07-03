<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Classes\ScraperClass;
use DB;

class ScrapeDataController extends Controller
{
    private $scraperClass;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(ScraperClass $scraperClass)
    {
        $this->scraperClass = $scraperClass;
    }
    
    public function index()
    {
        $arr_keywords = [];
        $file = fopen(base_path("public/csv/test.csv"),"r");
        while(!feof($file)) { $arr_keywords[] = (fgetcsv($file)[0]); }
        fclose($file);

        $start = 0;

        for ($i = $start; $i < $start + 10; $i++) {
                $keyword = urlencode($arr_keywords[$i]);
                $result = $this->scrape_insert_product($keyword, 2);
                if ($result['error']) {
                    var_dump($result);
                    sleep(60);
                }
                sleep(60);
        }
        var_dump('done');
    }

    //    ===============================================================================================
    //    ========================================SCRAPE FUNCTION========================================
    public function scrape_insert_product($keyword, $total_page = 0) {
        $response = array('error' => FALSE, 'error_msg' => '');

        if ($total_page) $last_page = $total_page;
        else $last_page = 1;
        for ($i = 1; $i <= $last_page; $i++) {
            
            $response = $this->scrape_lazada_search_result($keyword, $i);
            if ($response['error']) return $response;
           

            if (!$total_page) $last_page = $response['page']['last_page'];

            foreach ($response['products'] AS $product_id => $product) {
                $sql = 'SELECT * FROM wp_posts WHERE post_related_id = ' .$product_id;
                $result = DB::select($sql);
                if ($result) continue;

                $detail = isset($this->scrape_lazada_product_detail($product)['product_detail']);
                dd($detail);
                return $detail;
                $this->db_insert_product($detail);
            }
            sleep(60);
        }
        return $response;
    }

    public function scrape_lazada_search_result($keyword, $page = 1) {
        $result = array('error' => FALSE, 'error_msg' => '');
        
        if (!$keyword) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Invalid Parameters';
            return $result;
        }
        // $url = 'https://www.lazada.sg/catalog/?ajax=true&page='.$page.'&q='.$keyword;
        
        // response from url return json
        
        $url = base_path("public/csv/test.json");
        $data = json_decode(file_get_contents($url));
        if (!isset($data->mainInfo->totalResults)) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Blocked by Lazada, please wait for 1 minutes';
            $result['url'] = $data;
            return $result;
        }

        if ($data->mainInfo->totalResults === '0') {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Search no result';
            return $result;
        }

        $last_page = ceil($data->mainInfo->totalResults / $data->mainInfo->pageSize);
        if ($page > $last_page) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Failed to load data, the last page is ' . $last_page;
            return $result;
        }

        // get total items and last page
        $result['page'] = array(
            'total_items' => $data->mainInfo->totalResults,
            'page' => $page,
            'last_page' => $last_page,
            'page_size' => $data->mainInfo->pageSize,
        );

        // get product ID and url then assign to result array for return data
        $result['products'] = array();
        foreach ($data->mods->listItems AS $item) {
            $url = explode('/', $item->productUrl)[4];
            $result['products'][$item->nid] = $url;
        }

        return $result;
    }

    public function scrape_lazada_product_detail($product_id, $get_title = FALSE, $full_url = FALSE) {
        $result = array('error' => FALSE, 'error_msg' => '');

        if (!$product_id) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Invalid Parameters';
            return $result;
        }

        $url = ($full_url == TRUE ? $product_id : 'lazada.sg/products/' . $product_id);
        if (!$this->scraperClass->set_url($url)) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Invalid Url<br>Please update the url';
            return $result;
        }

        $html = $this->scraperClass->get_html();

        // get the index of app.run which contain product detail data
        $start_pos = strpos($html, 'app.run');
        if (!$start_pos) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Unable to get product detail from https://www.' . $url;
            return $result;
        }

        $data = substr($html, $start_pos + strlen('app.run') + 1);

        // get the index for closing bracket
        $end_pos = strpos($data, '});') + 1;

        $json = substr($data, 0, $end_pos);
        $json = json_decode($json);
        $data = $json->data->root->fields;

        if ($get_title == TRUE) return $data->product->title;

        $product_name = trim(substr($product_id, 0, strpos($product_id, '.html')));
        $sale_price = (isset_value($data->skuInfos->{'0'}->price->salePrice) ? $data->skuInfos->{'0'}->price->salePrice->value : $data->skuInfos->{'0'}->price->originalPrice->value);
        $product_detail = array(
            'lazada_item_id' => isset_value($data->primaryKey->itemId),
            'url' => isset_value($data->htmlRender->msiteShare->url),
            'categories' => isset_value($data->skuInfos->{'0'}->dataLayer->pdt_category),
            'title' => isset_value($data->product->title),
            'name' => isset_value($product_name),
            'image_url' => isset_value($data->htmlRender->msiteShare->image),
            'short_description' => isset_value($data->product->highlights),
            'description' => isset_value($data->product->desc),
            'sku' => isset_value($data->specifications->{$data->primaryKey->skuId}->features->SKU),
            'attributes' => $this->_get_lazada_product_properties($data),
            'variations' => $this->_get_lazada_product_options($data),
            'original_price' => isset_if_empty($data->skuInfos->{'0'}->price->originalPrice->value),
            'sale_price' => $sale_price,
        );


        // get shipping fees (if exists)
        $delivery_options = isset_value($data->deliveryOptions->{$data->primaryKey->skuId}, NULL);
        if ($delivery_options) {
            $min_fee = 1000;
            $shipping_title = '';
            foreach ($delivery_options as $idx => $option) {
                if (!isset_value($option->fee)) continue;
                if ($option->feeValue < $min_fee) {
                    $min_fee = $option->feeValue;
                    $shipping_title = $option->title;
                }
            }

            $product_detail['shipping'] = array(
                'title' => $shipping_title,
                'fee' => ($min_fee == 1000 ? NULL : $min_fee)
            );
        }

        $result['product_detail'] = $product_detail;
        return $result;
    }


    /*
     * Array detail MUST contain these detail:
     * name
     * price
     * sku
     * description
     * short_description
     * image_url
     * categories :
     *      array (
     *          * name
     *          * image_url
     *      ),
     *      array (
     *          * name
     *          * image_url
     *      )
     */
    function db_insert_product($detail) {
        $result = array('error' => FALSE, 'error_msg' => '', 'response' => NULL);

        if (!is_array_not_empty($detail)) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Product detail is empty. Please try again later.';
            return $result;
        }

        $lazada_item_id = isset_if_empty($detail['lazada_item_id'], 0);
        $prod_title = isset_if_empty($detail['title'], '');
        $prod_name = isset_if_empty($detail['lazada_item_id'], '');
        $prod_url = isset_if_empty($detail['url'], '');
        $prod_short_description = isset_if_empty($detail['short_description'], '');
        $prod_description = isset_if_empty($detail['description'], '');
        $original_price = isset_if_empty($detail['original_price'], '');
        $sale_price = isset_if_empty($detail['sale_price'], '');
        $stock = isset_if_empty($detail['stock'], 1000); // default value to make product always in stock
        $sku = isset_if_empty($detail['sku'], '');
        $image_url = isset_if_empty($detail['image_url'], '');
        $categories = isset_if_empty($detail['categories'], '');
        $attributes = isset_if_empty($detail['attributes'], '');
        $variations = isset_if_empty($detail['variations'], '');
        if (isset_value($detail['shipping'])) {
            $shipping_title = $detail['shipping']['title'];
            $shipping_fee = $detail['shipping']['fee'];
        }

        if (!$prod_name || !$sku || !$image_url || !$attributes) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Invalid product parameters. Please try again later.';
            return $result;
        }

        if (!is_array_not_empty($categories)) {
            $categories = array(
                array(
                    'name' => WOO_UNCATEGORIZED_NAME,
                    'image_url' => WOO_LOGO_UNCATEGORIZED
                )
            );
        }

        // INSERT PRODUCT
        $product = [
            'post_related_id' => $lazada_item_id,
            'post_author' => 1,
            'post_date' => date('Y-m-d H:i:s'),
            'post_date_gmt' => date('Y-m-d H:i:s'),
            'post_content' => $prod_description,
            'post_title' => $prod_title,
            'post_excerpt' => $prod_short_description,
            'post_status' => 'draft',
            'comment_status' => 'open',
            'ping_status' => 'closed',
            'post_name' => $prod_name,
            'post_modified' => date('Y-m-d H:i:s'),
            'post_modified_gmt' => date('Y-m-d H:i:s'),
            'post_parent' => 0,
            'guid' => $prod_url,
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
            'post_type' => 'product',
            'post_mime_type' => '',
            'shipping_title' => $shipping_title,
            'shipping_fee' => $shipping_fee,
        ];
        $product_id = DB::table('wp_posts')->insertGetId($product);

        //INSERT PHOTO
        $photo = [
            'post_author' => 0,
            'post_date' => date('Y-m-d H:i:s'),
            'post_date_gmt' => date('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => $prod_name,
            'post_excerpt' => '',
            'post_status' => 'inherit',
            'comment_status' => 'open',
            'ping_status' => 'open',
            'post_name' => '',
            'post_modified' => date('Y-m-d H:i:s'),
            'post_modified_gmt' => date('Y-m-d H:i:s'),
            'post_parent' => $product_id,
            'guid' => $image_url,
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
            'post_type' => 'attachment',
            'post_mime_type' => 'image/jpeg',
        ];
        $thumbnail_id = DB::table('wp_posts')->insertGetId($photo);

        //INSERT POST META
        $sql =
            'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) 
             VALUES 
             (' . $product_id . ', "_sku","' . $sku . '"),
             (' . $product_id . ', "_regular_price", ' . isset_if_empty($original_price, $sale_price) . '),
             (' . $product_id . ', "total_sales", 0),
             (' . $product_id . ', "_tax_status", "taxable"),
             (' . $product_id . ', "_tax_class", NULL),
             (' . $product_id . ', "_manage_stock", "no"),
             (' . $product_id . ', "_backorders", "no"),
             (' . $product_id . ', "_sold_individually", "no"),
             (' . $product_id . ', "_virtual", "no"),
             (' . $product_id . ', "_downloadable", "no"),
             (' . $product_id . ', "_download_limit", -1),
             (' . $product_id . ', "_download_expiry", -1),
             (' . $product_id . ', "_stock", '. $stock .'),
             (' . $product_id . ', "_stock_status", "instock"),
             (' . $product_id . ', "_wc_average_rating", 0),
             (' . $product_id . ', "_wc_review_count", 0),
             (' . $product_id . ', "_price", ' . $sale_price . '),
             (' . $product_id . ', "_sale_price", ' . isset_if_empty($sale_price, $original_price) . '),
             (' . $product_id . ', "fifu_image_url", "' . $image_url . '"),
             (' . $product_id . ', "fifu_image_alt", "' . $prod_name . '"),
             (' . $product_id . ', "_thumbnail_id", ' . $thumbnail_id . ')
             ';
        DB::insert($sql);

        $sql =
            'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) 
             VALUES 
             (' . $thumbnail_id . ', "_wp_attached_file",";' . $image_url . '"),
             (' . $thumbnail_id . ', "_wp_attachment_image_alt", "' . $prod_name . '")
             ';
        DB::insert($sql);

        $sql =
            'INSERT INTO wp_wc_product_meta_lookup
             VALUES (
                ' . $product_id . ',
                "' . $sku . '",
                0,
                0,
                '. isset_if_empty($sale_price, $original_price) .',
                '. isset_if_empty($sale_price, $original_price) .',
                0,
                '. $stock .',
                "instock",
                0,
                0,
                0
             )';
        DB::insert($sql);

        $result = $this->db_set_category($product_id, $categories);
        if ($result['error']) return $result;

        $result = $this->db_set_attribute($product_id, $attributes);
        if ($result['error']) return $result;

        $result = $this->db_insert_product_variation($product_id, $variations);
        if ($result['error']) return $result;

        $result['response'] = $product_id;
        return $result;
    }

    function db_set_category($product_id, $categories) {
        $result = array('error' => FALSE, 'error_msg' => '', 'response' => NULL);
        
        if (!isset_value($product_id) || !is_array_not_empty($categories)) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Invalid parameters. Please try again later.';
            return $result;
        }

        // delete all product category
        $sql = 'SELECT wtt.term_id
                    FROM wp_term_relationships wtr
                    INNER JOIN wp_term_taxonomy wtt
                    ON wtt.term_taxonomy_id = wtr.term_taxonomy_id AND wtt.taxonomy = "product_cat"
                    WHERE wtr.object_id = '.$product_id;
        $fetch = DB::select($sql);

        while($row = $fetch){
            $old_category_id = $row['term_id'];

            $sql = 'SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE term_id = '.$old_category_id;
            $fetch2 = DB::select($sql);
            $old_term_taxonomy_id = $fetch2['term_taxonomy_id'];

            $sql = 'UPDATE wp_term_taxonomy set count = (count - 1) where taxonomy = "product_cat" AND term_id = '.$old_category_id;
            DB::update($sql);

            $sql ='UPDATE wp_termmeta SET meta_value = (meta_value - 1) WHERE meta_key = "product_count_product_cat" and term_id = '.$old_category_id;
            DB::update($sql);

            $sql = 'DELETE FROM wp_term_relationships WHERE term_taxonomy_id = '.$old_term_taxonomy_id.' AND object_id = '.$product_id;
            DB::delete($sql);
        }

        foreach ($categories AS $category) {
            // search the category first
            $sql = 'SELECT wt.term_id AS term_id
                    FROM wp_terms wt 
                    INNER JOIN wp_term_taxonomy wtt 
                    ON wt.term_id = wtt.term_id 
                    AND wtt.taxonomy = "product_cat" 
                    WHERE lower(wt.name) = "'.strtolower($category).'" ';
            $fetch = DB::select($sql);
            $new_category_id = $fetch['term_id'];

            if (!isset_value($new_category_id)) {
                $new_category_id = WOO_UNCATEGORIZED_ID;
            }

            $sql = 'SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE term_id = "'.$new_category_id.'"';
            $fetch = DB::select($sql);
            $new_term_taxonomy_id = $fetch['term_taxonomy_id'];

            $sql = 'INSERT INTO wp_term_relationships VALUES ('.$product_id.', '.$new_term_taxonomy_id.', 0)';
            DB::insert($sql);

            $sql = 'UPDATE wp_term_taxonomy SET count = (count + 1) WHERE taxonomy = "product_cat" AND term_id = '.$new_category_id;
            DB::update($sql);

            $sql = 'UPDATE wp_termmeta SET meta_value = (meta_value + 1) WHERE meta_key = "product_count_product_cat" and term_id = '.$new_category_id;
            DB::update($sql);
        }
        return $result;
    }

    function db_set_attribute($product_id, $attributes) {
        $result = array('error' => FALSE, 'error_msg' => '', 'response' => NULL);

        if (!isset_value($product_id) || !is_array_not_empty($attributes)) {
            $result['error'] = TRUE;
            $result['error_msg'] = 'Invalid parameters. Please try again later.';
            return $result;
        }

        // attribute meta_value for the product
        $meta_value = array();

        foreach ($attributes AS $idx => $attribute) {
            // check duplicate and insert attribute if not duplicate
            $sql = 'SELECT * FROM wp_woocommerce_attribute_taxonomies WHERE attribute_label = ' . ($attribute['name']);
            $query_result = DB::select($sql);

            $attribute_id = $query_result->num_rows ? ($query_result)['attribute_id'] : $this->db_insert_attribute($attribute);
            if (!$attribute_id) {
                $result['error'] = TRUE;
                $result['error_msg'] = 'Failed to insert attribute';
                return $result;
            }

            // insert attribute value
            foreach ($attribute['values'] AS $idx => $value) {
                $sql = 'SELECT t.*, tx.term_taxonomy_id 
                    FROM wp_terms t
                    INNER JOIN wp_term_taxonomy tx ON tx.term_id = t.term_id 
                    WHERE t.name = ' . ($value);
                $query_result =  DB::select($sql);

                if (!$query_result) {
                    $result['error'] = TRUE;
                    $result['error_msg'] = 'connect db error';
                    return $result;
                }

                $attribute_value_id = $query_result->num_rows ?
                    ($query_result)['term_taxonomy_id'] :
                    $this->db_insert_attribute_value($attribute, $value);
                if (!$attribute_value_id) {
                    $result['error'] = TRUE;
                    $result['error_msg'] = 'Failed to insert attribute\'s value';
                    return $result;
                }

                // delete all the product attribute value
                $sql = 'DELETE FROM wp_term_relationship WHERE object_id = ' . $this->CI->db->escape($product_id);
                DB::delete($sql);


                // tag the product with the attribute value
                $sql = 'INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES(
                "'.$product_id.'",
                "'.$attribute_value_id.'")';
                DB::insert($sql);
            }

            // meta_value for attribute
            $prod_attribute = strtolower(str_replace( ' ', '-', $attribute['name']));
            $meta_value['pa_' . $prod_attribute] = array(
                'name' => 'pa_' . $prod_attribute,
                'value' => '',
                'position' => $idx,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1
            );
        }

        // insert product meta_value;
        $string_meta_value = $this->_convert_meta_value($meta_value);
        $sql = 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (
        "'.$product_id.'",
        "_product_attributes",
        '.($string_meta_value).')';
        DB::insert($sql);

        $this->db_clear_attribute_cache();
    }

    function db_insert_attribute_value($attribute, $value, $conn) {
        $value_name = isset_value($value, '');
        /*
         * Change the character
         * 1. whitespace to -
         * 2. & to and
         * 3. \/:*?"<>|'%,. to empty space
         */
        $value_slug = str_replace(' ', '-', strtolower($value_name));
        $value_slug = str_replace('&', 'and', $value_slug);
        $value_slug = str_replace(str_split('\\/:*?"<>|\'%,.'), '', $value_slug);
        // insert value
        $sql = 'INSERT INTO wp_terms (name, slug) VALUES (
                "'.$value_name.'",
                "'.$value_slug.'")';
        $rowAttribute = DB::select($sql);
        $attribute_value_id = $rowAttribute->id;
      

        // insert attribute value metadata
        $sql = 'INSERT INTO wp_termmeta (term_id, meta_key, meta_value) VALUES (
                "'.$attribute_value_id.'",
                "pa_'.strtolower(str_replace( ' ', '-', $attribute['name'])).'",
                "0")';
        DB::select($sql);

        // insert relation between attribute and value
        $sql = 'INSERT INTO wp_term_taxonomy (term_id, taxonomy, description) VALUES(
                "'.$attribute_value_id.'",
                "pa_'.strtolower(str_replace( ' ', '-', $attribute['name'])).'",
                "")';
        $rowTaxonomy = DB::select($sql);

        // return the id of relation between attribute and value
        return $rowTaxonomy->id;
    }

    function db_clear_attribute_cache() {
        $sql = 'DELETE FROM wp_options WHERE option_name = "_transient_wc_attribute_taxonomies"';
        DB::delete($sql);
    }
    function db_insert_product_variation($product_id, $variations) {
        $result = array('error' => FALSE, 'error_msg' => '');
        if (sizeof($variations) <= 1) {
            $result['response'] = TRUE;
            return $result;
        }

        // remove first variation array because it is the default variation
        array_shift($variations);

        $sql = 'SELECT * FROM wp_posts WHERE ID = ' . ($product_id);
        $product = DB::select($sql);
        $menu_order = 1;

        $product_gallery = '';
        foreach ($variations AS $lazada_variation_id => $variation) {
            $variation_title = $product['post_title'] . ' - ';
            $variation_excerpt = '';
            $variation_name = $product['post_name'];

            // get variation attribute value
            $insert_variation_meta = array();
            $attr_split = explode(';', $variation['attribute']);
            foreach ($attr_split AS $idx => $attribute) {
                $value_split = explode(':', $attribute);

                $attr = $value_split[0];
                $value = $value_split[1];

                $sql = 'SELECT * FROM wp_woocommerce_attribute_taxonomies WHERE attribute_label = ' . ($attr);
                $query_attr = DB::select($sql);

                $sql = 'SELECT * FROM wp_terms WHERE name = ' . $value;
                $query_attr_value = DB::select($sql);

                if ($idx) {
                    $variation_title .= ', ';
                    $variation_excerpt .= ', ';
                }

                $variation_title .= $query_attr_value['name'];
                $variation_excerpt .= $query_attr['attribute_label'] . ': ' . $query_attr_value['name'];
                $variation_name .= '-' . $query_attr_value['slug'];

                // insert meta data
                array_push($insert_variation_meta, array(
                    'meta_key' => 'attribute_pa_' . $query_attr['attribute_name'],
                    'meta_value' => $query_attr_value['slug']
                ));
            }

            // insert product variation
             $productVariation = [
                'post_related_id' => $lazada_variation_id,
                'post_author' => 1,
                'post_date' => date('Y-m-d H:i:s'),
                'post_date_gmt' => date('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => $variation_title,
                'post_excerpt' => $variation_excerpt,
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_name' => $variation_name,
                'post_modified' => date('Y-m-d H:i:s'),
                'post_modified_gmt' => date('Y-m-d H:i:s'),
                'post_parent' => $product_id,
                'guid' => $variation['url'],
                'menu_order' => $menu_order,
                'to_ping' => '',
                'pinged' => '',
                'post_content_filtered' => '',
                'post_type' => 'product_variation',
                'post_mime_type' => ''
            ];
            $variation_id = DB::table('wp_posts')->insertGetId($productVariation);
            $menu_order++;

            //INSERT PHOTO
            $photo = [
                'post_author' => 0,
                'post_date' => date('Y-m-d H:i:s'),
                'post_date_gmt' => date('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => $variation_name,
                'post_excerpt' => '',
                'post_status' => 'inherit',
                'comment_status' => 'open',
                'ping_status' => 'open',
                'post_name' => '',
                'post_modified' => date('Y-m-d H:i:s'),
                'post_modified_gmt' => date('Y-m-d H:i:s'),
                'post_parent' => $variation_id,
                'guid' => $variation['image_url'],
                'to_ping' => '',
                'pinged' => '',
                'post_content_filtered' => '',
                'post_type' => 'attachment',
                'post_mime_type' => 'image/jpeg',
            ];
            $variation_id = DB::table('wp_posts')->insertGetId($photo);

            $product_gallery .= $thumbnail_id . ',';

            //INSERT POST META
            $sql = 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
            ('.$product_id.', "_price", "'.$variation['sale_price'].'")';
            DB::insert($sql);

            $sql =
                'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) 
             VALUES 
             (' . $variation_id . ', "_sku","' . $variation['sku'] . '"),
             (' . $variation_id . ', "_regular_price", ' . isset_if_empty($variation['original_price'], $variation['sale_price']) . '),
             (' . $variation_id . ', "total_sales", 0),
             (' . $variation_id . ', "_tax_status", "taxable"),
             (' . $variation_id . ', "_tax_class", NULL),
             (' . $variation_id . ', "_manage_stock", "no"),
             (' . $variation_id . ', "_backorders", "no"),
             (' . $variation_id . ', "_sold_individually", "no"),
             (' . $variation_id . ', "_virtual", "no"),
             (' . $variation_id . ', "_downloadable", "no"),
             (' . $variation_id . ', "_download_limit", -1),
             (' . $variation_id . ', "_download_expiry", -1),
             (' . $variation_id . ', "_stock", '. isset_if_empty($variation['stock'], 1000).'),
             (' . $variation_id . ', "_stock_status", "instock"),
             (' . $variation_id . ', "_wc_average_rating", 0),
             (' . $variation_id . ', "_wc_review_count", 0),
             (' . $variation_id . ', "_price", ' . isset_if_empty($variation['sale_price'], $variation['original_price']) . '),
             (' . $variation_id . ', "_sale_price", ' . isset_if_empty($variation['sale_price'], $variation['original_price']) . '),
             (' . $variation_id . ', "fifu_image_url", "' . $variation['image_url'] . '"),
             (' . $variation_id . ', "fifu_image_alt", "' . $variation_name . '"),
             (' . $variation_id . ', "_thumbnail_id", ' . $thumbnail_id . ')
             ';
            DB::insert($sql);

            $sql =
                'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) 
             VALUES 
             (' . $thumbnail_id . ', "_wp_attached_file",";' . $variation['image_url'] . '"),
             (' . $thumbnail_id . ', "_wp_attachment_image_alt", "' . $variation_name . '")
             ';
            DB::insert($sql);

            // Insert variation attribute meta data
            foreach ($insert_variation_meta AS $insert_data) {
                $sql = 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (
                '.($variation_id).',
                '.($insert_data['meta_key']).',
                '.($insert_data['meta_value']).'
                )';
                DB::insert($sql);
            }

            // Change product type to variation
            $sql = 'INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES (
            ' . ($product_id) . ', "4", "0")';
            DB::insert($sql);
        }

        $sql = 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (
        ' . ($product_id) . ',
        "_product_image_gallery",
        ' . (substr($product_gallery, 0, -1)) . ')';
        DB::insert($sql);

        $result['response'] = 'success';
        return $result;
    }

}