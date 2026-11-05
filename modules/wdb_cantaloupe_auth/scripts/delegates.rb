# frozen_string_literal: true
# JRuby delegate script for Cantaloupe pre-authorization against Drupal (WDB).
# Copy this file to your Cantaloupe delegates path (e.g., delegates.rb) or require it
# from your existing delegate.
#
# Configuration is read from the environment (all optional except the endpoint):
#
#   DRUPAL_AUTH_ENDPOINT   URL of /wdb/api/cantaloupe_auth.
#                          Prefer a loopback plain-HTTP URL - see README.
#   WDB_AUTH_HOST_HEADER   Host header to send when the endpoint is a loopback
#                          address (must match Drupal's trusted_host_patterns).
#   WDB_TOKEN_PARAM        Query parameter carrying the token (default wdb_token).
#   WDB_TOKEN_ONLY         "true" to disable the cookie/session fallback.
#   WDB_SKIP_AUTH_DIRS     Comma-separated identifier path segments to serve
#                          without any authorization check (e.g. "sample,public").
#   WDB_AUTH_CACHE_TTL     Seconds to cache an authorization decision
#                          (default 60; set 0 to disable). See README for the
#                          logout/expiry trade-off.
#
# IMPORTANT - do not delete the stub methods below.
#
#   Cantaloupe 5.x calls every method declared in its bundled
#   `delegates.rb.sample` (metadata, redactions, overlay, source, ...). A method
#   that is absent raises NoMethodError inside JRuby, which Cantaloupe turns
#   into a 500 response. A missing `metadata` in particular breaks *every*
#   info.json request. All sample methods are therefore defined here, with
#   their upstream documentation comments, even when the body is the default
#   stub. When upgrading Cantaloupe, diff this file against the new
#   `delegates.rb.sample` and port over any newly added method.

require 'net/http'
require 'uri'
require 'json'
require 'digest'

# Cantaloupe v5 JRuby delegates are typically class-based (CustomDelegate with
# an instance-level `context` accessor). This script supports that style by
# defining (or reopening) CustomDelegate.
class CustomDelegate
  ##
  # Attribute for the request context, which is a hash containing information
  # about the current request.
  #
  # This attribute will be set by the server before any other methods are
  # called. Methods can access its keys like:
  #
  # ```
  # identifier = context['identifier']
  # ```
  #
  # The hash will contain the following keys in response to all requests:
  #
  # * `client_ip`        [String] Client IP address.
  # * `cookies`          [Hash<String,String>] Hash of cookie name-value pairs.
  # * `full_size`        [Hash<String,Integer>] Hash with `width` and `height`
  #                      keys corresponding to the pixel dimensions of the
  #                      source image.
  # * `identifier`       [String] Image identifier.
  # * `local_uri`        [String] URI seen by the application, which may be
  #                      different from `request_uri` when operating behind a
  #                      reverse-proxy server.
  # * `metadata`         [Hash<String,Object>] Embedded image metadata. Object
  #                      structure varies depending on the source image.
  #                      See the `metadata()` method.
  # * `page_count`       [Integer] Page count.
  # * `page_number`      [Integer] Page number.
  # * `request_headers`  [Hash<String,String>] Hash of header name-value pairs.
  # * `request_uri`      [String] URI requested by the client.
  # * `scale_constraint` [Array<Integer>] Two-element array with scale
  #                      constraint numerator at position 0 and denominator at
  #                      position 1.
  #
  # It will contain the following additional string keys in response to image
  # requests, after the image has been accessed:
  #
  # * `operations`     [Array<Hash<String,Object>>] Array of operations in
  #                    order of application. Only operations that are not
  #                    no-ops will be included. Every hash contains a `class`
  #                    key corresponding to the operation class name, which
  #                    will be one of the `e.i.l.c.operation.Operation`
  #                    implementations.
  # * `output_format`  [String] Output format media (MIME) type.
  # * `resulting_size` [Hash<String,Integer>] Hash with `width` and `height`
  #                    keys corresponding to the pixel dimensions of the
  #                    resulting image after all operations have been applied.
  #
  # @return [Hash] Request context.
  #
  attr_accessor :context unless method_defined?(:context)
end unless defined?(CustomDelegate)

class CustomDelegate

  # Drupal authorization endpoint.
  #
  # This is called once per IIIF request, so the transport matters: going out
  # through the public HTTPS URL costs a TLS handshake plus a round trip on the
  # external interface. Measured on a reference deployment that was ~25-30ms
  # per request against ~7ms to generate the tile itself. Pointing this at a
  # loopback plain-HTTP vhost removes that overhead. See the README section
  # "Reverse proxy requirements" for the vhost definition.
  DRUPAL_AUTH_ENDPOINT = ENV['DRUPAL_AUTH_ENDPOINT'] ||
                         'https://example.org/wdb/api/cantaloupe_auth'

  # Host header to send with the request. Required when DRUPAL_AUTH_ENDPOINT
  # points at 127.0.0.1, because Drupal resolves the site (and
  # trusted_host_patterns) from this header.
  DRUPAL_AUTH_HOST_HEADER = ENV['WDB_AUTH_HOST_HEADER']

  TOKEN_QUERY_PARAM    = ENV['WDB_TOKEN_PARAM'] || 'wdb_token'
  TOKEN_ONLY_MODE      = ENV['WDB_TOKEN_ONLY'] == 'true'
  TOKEN_HEADER_CANDIDATES = [
    'X-Wdb-Token',
    'X-Original-URI',
    'X-Original-URL',
    'X-Forwarded-URI',
    'X-Forwarded-URL',
  ]

  # Identifier path segments served without any authorization check.
  # Anything matching is returned before the Drupal call, so use it only for
  # material that is genuinely public.
  SKIP_AUTH_DIRECTORIES = (ENV['WDB_SKIP_AUTH_DIRS'] || '')
                          .split(',').map(&:strip).reject(&:empty?)

  # Authorization result cache.
  #
  # A single IIIF viewport pulls ~20 tiles and Cantaloupe calls the delegate for
  # each one, so without caching one page view means ~20 full Drupal
  # bootstraps. The cache key covers the identifier, the token and the cookies,
  # so a different user, a different token or a different image never reuses
  # another entry.
  #
  # Trade-off: a decision can outlive the state it was based on by up to TTL
  # seconds. After a logout or a token expiry, tiles for images the user had
  # already opened keep loading for that long. Keep the TTL well below
  # `token_ttl` (default 600s), or set WDB_AUTH_CACHE_TTL=0 to disable.
  AUTH_CACHE_TTL      = (ENV['WDB_AUTH_CACHE_TTL'] || '60').to_i
  AUTH_CACHE_MAX_SIZE = 10_000

  @@auth_cache       = {}
  @@auth_cache_mutex = Mutex.new

  # Extract token from a query string-containing string (URI or raw query)
  # using the configured TOKEN_QUERY_PARAM.
  def extract_token_from_string(value, param_name = TOKEN_QUERY_PARAM)
    return nil unless value.is_a?(String) && !value.empty?

    query = nil
    if value.include?('?')
      query = value.split('?', 2)[1]
    elsif value.include?('=')
      query = value
    end

    return nil if query.nil? || query.empty?

    query.split(/[&;]/).each do |pair|
      key, token_value = pair.split('=', 2)
      next if key.nil? || key.empty?
      if key == param_name
        return token_value ? URI.decode_www_form_component(token_value) : ''
      end
    end
    nil
  end

  # Resolve token from common Cantaloupe request context fields and headers.
  # Expects a global/context method `context` provided by Cantaloupe.
  def resolve_token_from_context
    # Request URI first
    token = extract_token_from_string(context && context['request_uri'])
    return token if token && !token.empty?

    # Try forwarded headers
    headers = (context && context['request_headers']) || {}
    downcased = {}
    headers.each { |k, v| downcased[k.to_s.downcase] = v if k }

    TOKEN_HEADER_CANDIDATES.each do |header_name|
      candidate = headers[header_name] || downcased[header_name.downcase]
      token = extract_token_from_string(candidate)
      return token if token && !token.empty?
    end

    # Finally, local URI (if present)
    local_uri = context && context['local_uri']
    extract_token_from_string(local_uri)
  end

  # Look up a cached authorization decision, or nil on miss/expiry.
  def cached_authorization(key)
    return nil if AUTH_CACHE_TTL <= 0
    @@auth_cache_mutex.synchronize do
      entry = @@auth_cache[key]
      next nil if entry.nil?
      if entry[:expires_at] <= Time.now.to_f
        @@auth_cache.delete(key)
        next nil
      end
      entry[:authorized]
    end
  end

  # Store an authorization decision and return it. The cache is bounded; once
  # it fills up it is cleared wholesale rather than evicted entry by entry,
  # which keeps the critical section short.
  def store_authorization(key, authorized)
    return authorized if AUTH_CACHE_TTL <= 0
    @@auth_cache_mutex.synchronize do
      @@auth_cache.clear if @@auth_cache.size >= AUTH_CACHE_MAX_SIZE
      @@auth_cache[key] = {
        authorized: authorized,
        expires_at: Time.now.to_f + AUTH_CACHE_TTL,
      }
    end
    authorized
  end

  ##
  # Deserializes the given meta-identifier string into a hash of its component
  # parts.
  #
  # This method is used only when the `meta_identifier.transformer`
  # configuration key is set to `DelegateMetaIdentifierTransformer`.
  #
  # The hash contains the following keys:
  #
  # * `identifier`       [String] Required.
  # * `page_number`      [Integer] Optional.
  # * `scale_constraint` [Array<Integer>] Two-element array with scale
  #                      constraint numerator at position 0 and denominator at
  #                      position 1. Optional.
  #
  # @param meta_identifier [String]
  # @return Hash<String,Object> See above. The return value should be
  #                             compatible with the argument to
  #                             {serialize_meta_identifier}.
  #
  def deserialize_meta_identifier(meta_identifier)
  end

  ##
  # Serializes the given meta-identifier hash.
  #
  # This method is used only when the `meta_identifier.transformer`
  # configuration key is set to `DelegateMetaIdentifierTransformer`.
  #
  # See {deserialize_meta_identifier} for a description of the hash structure.
  #
  # @param components [Hash<String,Object>]
  # @return [String] Serialized meta-identifier compatible with the argument to
  #                  {deserialize_meta_identifier}.
  #
  def serialize_meta_identifier(components)
  end

  ##
  # Returns authorization status for the current request. This method is called
  # upon all requests to all public endpoints early in the request cycle,
  # before the image has been accessed. This means that some context keys (like
  # `full_size`) will not be available yet.
  #
  # This method should implement all possible authorization logic except that
  # which requires any of the context keys that aren't yet available. This will
  # ensure efficient authorization failures.
  #
  # Implementations should assume that the underlying resource is available,
  # and not try to check for it.
  #
  # Possible return values:
  #
  # 1. Boolean true/false, indicating whether the request is fully authorized
  #    or not. If false, the client will receive a 403 Forbidden response.
  # 2. Hash with a `status_code` key.
  #     a. If it corresponds to an integer from 200-299, the request is
  #        authorized.
  #     b. If it corresponds to an integer from 300-399:
  #         i. If the hash also contains a `location` key corresponding to a
  #            URI string, the request will be redirected to that URI using
  #            that code.
  #         ii. If the hash also contains `scale_numerator` and
  #            `scale_denominator` keys, the request will be
  #            redirected using that code to a virtual reduced-scale version of
  #            the source image.
  #     c. If it corresponds to 401, the hash must include a `challenge` key
  #        corresponding to a WWW-Authenticate header value.
  #
  # @param options [Hash] Empty hash.
  # @return [Boolean,Hash<String,Object>] See above.
  #
  def pre_authorize(options = {})
    begin
      request_uri_str = (context && context['request_uri']).to_s
      # Cantaloupe supplies the decoded identifier of the actual resource.
      # Never use request_uri (which includes attacker-controlled query data)
      # or client_ip to bypass authorization, including for info.json.
      identifier_segments = (context && context['identifier']).to_s.split('/')
      unless identifier_segments.empty? || identifier_segments.any? { |part| part.empty? || part == '.' || part == '..' || part.include?('%') || part.include?('\\') }
        return true if identifier_segments.any? { |part| SKIP_AUTH_DIRECTORIES.include?(part) }
      end

      token = resolve_token_from_context

      cookies = []
      unless TOKEN_ONLY_MODE
        cookies_hash = (context && context['cookies']) || {}
        # JRuby may give us a Java map; prefer each_pair compatibility.
        if cookies_hash.respond_to?(:map)
          cookies = cookies_hash.map { |k, v| "#{k}=#{v}" }
        else
          tmp = []
          cookies_hash.each { |k, v| tmp << "#{k}=#{v}" }
          cookies = tmp
        end
      end

      payload_hash = {
        identifier: context && context['identifier'],
        request_uri: request_uri_str,
      }
      payload_hash[:token] = token if token && !token.empty?
      payload_hash[:cookies] = cookies unless cookies.empty?

      # The cache key deliberately excludes request_uri: a viewer requests many
      # different regions and sizes of one identifier, and Drupal's decision
      # depends only on who is asking for which image.
      cache_key = Digest::SHA256.hexdigest([
        payload_hash[:identifier],
        payload_hash[:token],
        payload_hash[:cookies],
      ].inspect)

      cached = cached_authorization(cache_key)
      return cached unless cached.nil?

      payload = payload_hash.to_json

      endpoint = DRUPAL_AUTH_ENDPOINT
      return false if endpoint.nil? || endpoint.empty?

      uri = URI.parse(endpoint)
      http = Net::HTTP.new(uri.host, uri.port)
      http.use_ssl = (uri.scheme == 'https')
      http.open_timeout = 2
      http.read_timeout = 5

      request = Net::HTTP::Post.new(uri.request_uri, 'Content-Type' => 'application/json')
      if DRUPAL_AUTH_HOST_HEADER && !DRUPAL_AUTH_HOST_HEADER.empty?
        request['Host'] = DRUPAL_AUTH_HOST_HEADER
        # Drupal treats the request as HTTPS even though the hop is plain HTTP.
        request['X-Forwarded-Proto'] = 'https'
      end
      request.body = payload

      response = http.request(request)

      if response.is_a?(Net::HTTPSuccess)
        auth_result = JSON.parse(response.body)
        return store_authorization(cache_key, !!auth_result['authorized'])
      end

      store_authorization(cache_key, false)
    rescue => e
      log_warn("Delegate pre_authorize error: #{e.class}: #{e.message}")
      false
    end
  end

  ##
  # Returns authorization status for the current request. Will be called upon
  # all requests to all public image (not information) endpoints.
  #
  # This is a counterpart of `pre_authorize()` that is invoked later in the
  # request cycle, once more information about the underlying image has become
  # available. It should only contain logic that depends on context keys that
  # contain information about the source image (like `full_size`, `metadata`,
  # etc.)
  #
  # Implementations should assume that the underlying resource is available,
  # and not try to check for it.
  #
  # @param options [Hash] Empty hash.
  # @return [Boolean,Hash<String,Object>] See the documentation of
  #                                       `pre_authorize()`.
  #
  # Some Cantaloupe configurations/versions call `authorize` instead of
  # `pre_authorize`. Keep a compatible alias so the same script works in both.
  # When both are called for one request, the second call is served from the
  # authorization cache.
  def authorize(options = {})
    pre_authorize(options)
  end

  ##
  # Adds additional keys to an Image API 2.x information response. See the
  # [IIIF Image API 2.1](http://iiif.io/api/image/2.1/#image-information)
  # specification and "endpoints" section of the user manual.
  #
  # @param options [Hash] Empty hash.
  # @return [Hash] Hash to merge into an Image API 2.x information response.
  #                Return an empty hash to add nothing.
  #
  def extra_iiif2_information_response_keys(options = {})
    {}
  end

  ##
  # Adds additional keys to an Image API 3.x information response. See the
  # [IIIF Image API 3.0](http://iiif.io/api/image/3.0/#image-information)
  # specification and "endpoints" section of the user manual.
  #
  # @param options [Hash] Empty hash.
  # @return [Hash] Hash to merge into an Image API 3.x information response.
  #                Return an empty hash to add nothing.
  #
  def extra_iiif3_information_response_keys(options = {})
    {}
  end

  ##
  # Tells the server which source to use for the given identifier.
  #
  # @param options [Hash] Empty hash.
  # @return [String] Source name.
  #
  def source(options = {})
  end

  ##
  # N.B.: this method should not try to perform authorization. `authorize()`
  # should be used instead.
  #
  # @param options [Hash] Empty hash.
  # @return [String,nil] Blob key of the image corresponding to the given
  #                      identifier, or nil if not found.
  #
  def azurestoragesource_blob_key(options = {})
  end

  ##
  # N.B.: this method should not try to perform authorization. `authorize()`
  # should be used instead.
  #
  # @param options [Hash] Empty hash.
  # @return [String,nil] Absolute pathname of the image corresponding to the
  #                      given identifier, or nil if not found.
  #
  def filesystemsource_pathname(options = {})
  end

  ##
  # Returns one of the following:
  #
  # 1. String URI
  # 2. Hash with the following keys:
  #     * `uri`               [String] (required)
  #     * `username`          [String] For HTTP Basic authentication
  #                           (optional).
  #     * `secret`            [String] For HTTP Basic authentication
  #                           (optional).
  #     * `headers`           [Hash<String,String>] Hash of request headers
  #                           (optional).
  #     * `send_head_request` [Boolean] Optional; defaults to `true`. See the
  #                           documentation of the
  #                           `HttpSource.BasicLookupStrategy.send_head_requests`
  #                           configuration key.
  # 3. nil if not found.
  #
  # N.B.: this method should not try to perform authorization. `authorize()`
  # should be used instead.
  #
  # @param options [Hash] Empty hash.
  # @return See above.
  #
  def httpsource_resource_info(options = {})
  end

  ##
  # N.B.: this method should not try to perform authorization. `authorize()`
  # should be used instead.
  #
  # @param options [Hash] Empty hash.
  # @return [String, nil] Database identifier of the image corresponding to the
  #                       identifier in the context, or nil if not found.
  #
  def jdbcsource_database_identifier(options = {})
  end

  ##
  # Returns either the last-modified timestamp of an image in ISO 8601 format,
  # or an SQL statement that can be used to retrieve it from a `TIMESTAMP`-type
  # column in the database. In the latter case, the "SELECT" and "FROM" clauses
  # should be in uppercase in order to be autodetected.
  #
  # Implementing this method is optional, but may be necessary for certain
  # features (like `Last-Modified` response headers) to work.
  #
  # @param options [Hash] Empty hash.
  # @return [String, nil]
  #
  def jdbcsource_last_modified(options = {})
  end

  ##
  # Returns either the media (MIME) type of an image, or an SQL statement that
  # can be used to retrieve it from a `CHAR`-type column in the database. In
  # the latter case, the "SELECT" and "FROM" clauses should be in uppercase in
  # order to be autodetected. If nil is returned, the media type will be
  # inferred some other way, such as by identifier extension or magic bytes.
  #
  # @param options [Hash] Empty hash.
  # @return [String, nil]
  #
  def jdbcsource_media_type(options = {})
  end

  ##
  # @param options [Hash] Empty hash.
  # @return [String] SQL statement that selects the BLOB corresponding to the
  #                  value returned by `jdbcsource_database_identifier()`.
  #
  def jdbcsource_lookup_sql(options = {})
  end

  ##
  # N.B.: this method should not try to perform authorization. `authorize()`
  # should be used instead.
  #
  # @param options [Hash] Empty hash.
  # @return [Hash<String,Object>,nil] Hash containing `bucket` and `key` keys.
  #         It may also contain an `endpoint` key, indicating that the endpoint
  #         is different from the one set in the configuration. In that case,
  #         it may also contain `region`, `access_key_id`, and/or
  #         `secret_access_key` keys.
  #
  def s3source_object_info(options = {})
  end

  ##
  # Tells the server what overlay, if any, to apply to an image. Called upon
  # all image requests to any endpoint if overlays are enabled and the overlay
  # strategy is set to `ScriptStrategy` in the application configuration.
  #
  # Return values:
  #
  # 1. For string overlays, a hash with the following keys:
  #     * `background_color` [String] CSS-compliant RGA(A) color.
  #     * `color`            [String] CSS-compliant RGA(A) color.
  #     * `font`             [String] Font name. Launch with the -list-fonts
  #                          argument to see a list of available fonts.
  #     * `font_min_size`    [Integer] Minimum font size in points (ignored
  #                          when `word_wrap` is true).
  #     * `font_size`        [Integer] Font size in points.
  #     * `font_weight`      [Float] Font weight based on 1.
  #     * `glyph_spacing`    [Float] Glyph spacing based on 0.
  #     * `inset`            [Integer] Pixels of inset.
  #     * `position`         [String] Position like `top left`, `center right`,
  #                          etc.
  #     * `string`           [String] String to draw.
  #     * `stroke_color`     [String] CSS-compliant RGB(A) text outline color.
  #     * `stroke_width`     [Float] Text outline width in pixels.
  #     * `word_wrap`        [Boolean] Whether to wrap long lines within
  #                          `string`.
  # 2. For image overlays, a hash with the following keys:
  #     * `image`    [String] Image pathname or URL.
  #     * `position` [String] See above.
  #     * `inset`    [Integer] See above.
  # 3. nil for no overlay.
  #
  # @param options [Hash] Empty hash.
  # @return See above.
  #
  def overlay(options = {})
  end

  ##
  # Tells the server what regions of an image to redact in response to a
  # particular request. Will be called upon all image requests to any endpoint.
  #
  # @param options [Hash] Empty hash.
  # @return [Array<Hash<String,Integer>>] Array of hashes, each with `x`, `y`,
  #         `width`, and `height` keys; or an empty array if no redactions are
  #         to be applied.
  #
  def redactions(options = {})
    []
  end

  ##
  # Returns XMP metadata to embed in the derivative image.
  #
  # Source image metadata is available in the `metadata` context key, and has
  # the following structure:
  #
  # ```
  # {
  #     "exif": {
  #         "tagSet": "Baseline TIFF",
  #         "fields": {
  #             "Field1Name": value,
  #             "Field2Name": value,
  #             "EXIFIFD": {
  #                 "tagSet": "EXIF",
  #                 "fields": {
  #                     "Field1Name": value,
  #                     "Field2Name": value
  #                 }
  #             }
  #         }
  #     },
  #     "iptc": [
  #         "Field1Name": value,
  #         "Field2Name": value
  #     ],
  #     "xmp_string": "<rdf:RDF>...</rdf:RDF>",
  #     "xmp_model": See https://jena.apache.org/documentation/javadoc/jena/org/apache/jena/rdf/model/Model.html,
  #     "xmp_elements": {
  #         "Field1Name": "value",
  #         "Field2Name": [
  #             "value1",
  #             "value2"
  #         ]
  #     },
  #     "native": {
  #         # structure varies
  #     }
  # }
  # ```
  #
  # * The `exif` key refers to embedded EXIF data. This also includes IFD0
  #   metadata from source TIFFs, whether or not an EXIF IFD is present.
  # * The `iptc` key refers to embedded IPTC IIM data.
  # * The `xmp_string` key refers to raw embedded XMP data.
  # * The `xmp_model` key contains a Jena Model object pre-loaded with the
  #   contents of `xmp_string`.
  # * The `xmp_elements` key contains a view of the embedded XMP data as key-
  #   value pairs. This is convenient to use, but may not work correctly with
  #   all XMP fields--in particular, those that cannot be expressed as
  #   key-value pairs.
  # * The `native` key refers to format-specific metadata.
  #
  # Any combination of the above keys may be present or missing depending on
  # what is available in a particular source image.
  #
  # Only XMP can be embedded in derivative images. See the user manual for
  # examples of working with the XMP model programmatically.
  #
  # @return [String,Model,nil] String or Jena model containing XMP data to
  #                            embed in the derivative image, or nil to not
  #                            embed anything.
  #
  def metadata(options = {})
  end

  def log_warn(message)
    if defined?(Java) && defined?(Java::org) && defined?(Java::org.slf4j)
      logger.warn(message)
    else
      warn(message)
    end
  rescue
    # As a last resort, swallow logging errors to avoid breaking IIIF requests.
    false
  end

  def logger
    @logger ||= Java::org.slf4j.LoggerFactory.getLogger('wdb_delegate')
  end

end
