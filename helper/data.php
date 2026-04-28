<?php

function show_array($data) {
    if (is_array($data)) {
        echo "<pre>";
        print_r($data);
        echo "<pre>";
    }
}

function insert_after($array, $after, $value) {
    $result = [];
    foreach ($array as $item) {
        $result[] = $item;
        if ($item == $after) {
            $result[] = $value;
        }
    }
    return $result;
}
function insert_after_assoc($array, $after_key, $new_key, $new_value) {
    $result = [];
    foreach ($array as $key => $value) {
        $result[$key] = $value;

        if ($key == $after_key) {
            $result[$new_key] = $new_value;
        }
    }
    return $result;
}