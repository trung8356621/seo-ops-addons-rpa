<?php
namespace Illuminate\Database\Eloquent {
    class Model {
        protected $guarded = [];
        protected $casts = [];
        protected $attributes = [];
        public function __construct(array $attributes = []) {
            foreach ($attributes as $k => $v) { $this->attributes[$k] = $v; }
        }
        public function getAttribute($key) { return $this->attributes[$key] ?? ($this->$key ?? null); }
        public function setAttribute($key, $value) { $this->attributes[$key] = $value; return $this; }
        public function forceFill(array $attributes) {
            foreach ($attributes as $k => $v) { $this->attributes[$k] = $v; }
            return $this;
        }
        public function __get($key) { return $this->attributes[$key] ?? null; }
        public function __set($key, $value) { $this->attributes[$key] = $value; }
        public function __isset($key) { return isset($this->attributes[$key]); }
    }
}
namespace Illuminate\Database\Eloquent\Relations {
    class BelongsTo {}
}