#!/usr/bin/env ruby
# Minimal harness to validate the delegates.rb sample.
#
# Usage:
#   DRUPAL_AUTH_ENDPOINT="https://your.host.name/wdb/api/cantaloupe_auth" \
#   ruby delegate_harness.rb \
#     --identifier wdb/hdb/bm10221/1.ptif \
#     --cookies "SSESSxxxx=abc; _ga=1" \
#     --client-ip 203.0.113.9 \
#     --request-uri "/iiif/3/wdb%2Fhdb%2Fbm10221%2F1.ptif/full/max/0/default.jpg?wdb_token=YOUR.TOKEN.VALUE"
#
# To check only that every delegate method Cantaloupe calls is defined, without
# contacting Drupal:
#   ruby delegate_harness.rb --check-methods
#
# Returns exit status 0 if authorized, 1 otherwise, 2 on usage errors.

require 'net/http'
require 'uri'
require 'json'
require_relative 'delegates'
require 'optparse'

# Every method Cantaloupe 5.x invokes on CustomDelegate, as declared in the
# bundled delegates.rb.sample. A missing one raises NoMethodError inside JRuby
# and Cantaloupe returns 500 - a missing `metadata` breaks every info.json
# request. Keep this list in sync when upgrading Cantaloupe.
REQUIRED_DELEGATE_METHODS = %w[
  deserialize_meta_identifier
  serialize_meta_identifier
  pre_authorize
  authorize
  extra_iiif2_information_response_keys
  extra_iiif3_information_response_keys
  source
  azurestoragesource_blob_key
  filesystemsource_pathname
  httpsource_resource_info
  jdbcsource_database_identifier
  jdbcsource_last_modified
  jdbcsource_media_type
  jdbcsource_lookup_sql
  s3source_object_info
  overlay
  redactions
  metadata
].freeze

# Verifies that the delegate implements every method Cantaloupe calls.
# Returns the list of missing method names.
def missing_delegate_methods
  delegate = CustomDelegate.new
  REQUIRED_DELEGATE_METHODS.reject { |m| delegate.respond_to?(m) }
end

opts = {
  'identifier' => nil,
  'cookies' => '',
  'client_ip' => '203.0.113.9',
  'request_uri' => '/iiif/3/info.json',
  'check_methods_only' => false
}

OptionParser.new do |o|
  o.on('--identifier ID', 'IIIF identifier, e.g., wdb/hdb/foo/1.ptif') { |v| opts['identifier'] = v }
  o.on('--cookies STR', 'Cookie header string, e.g., "SSESS...=...; _ga=..."') { |v| opts['cookies'] = v }
  o.on('--client-ip IP', 'Client IP (default 203.0.113.9)') { |v| opts['client_ip'] = v }
  o.on('--request-uri URI', 'Request URI (default /iiif/3/info.json)') { |v| opts['request_uri'] = v }
  o.on('--check-methods', 'Only verify that all delegate methods are defined, then exit') { opts['check_methods_only'] = true }
end.parse!

# Method coverage check. This runs on every invocation because a delegate that
# authorizes correctly but is missing, say, `metadata` still returns 500 to
# every client.
missing = missing_delegate_methods
if missing.empty?
  warn "delegate method check: OK (#{REQUIRED_DELEGATE_METHODS.size} methods)"
else
  warn "delegate method check: MISSING #{missing.size} method(s): #{missing.join(', ')}"
  warn 'Cantaloupe will return HTTP 500 for requests that call them.'
  exit 2 unless opts['check_methods_only']
end
if opts['check_methods_only']
  exit(missing.empty? ? 0 : 1)
end

# The delegate reads DRUPAL_AUTH_ENDPOINT from the environment at load time,
# so it is already applied by the time we get here; check it was actually set.
if ENV['DRUPAL_AUTH_ENDPOINT'].nil? || ENV['DRUPAL_AUTH_ENDPOINT'].empty?
  warn 'DRUPAL_AUTH_ENDPOINT must be set as an environment variable.'
  exit 2
end

# The authorization cache would mask repeated runs against changing state.
unless ENV.key?('WDB_AUTH_CACHE_TTL')
  warn 'Note: WDB_AUTH_CACHE_TTL is unset, so decisions are cached for 60s. ' \
       'Set WDB_AUTH_CACHE_TTL=0 when testing changing permissions.'
end

# Emulate Cantaloupe's context hash.
context = {
  'identifier' => opts['identifier'],
  'client_ip' => opts['client_ip'],
  'request_uri' => opts['request_uri'],
  'local_uri' => opts['request_uri'],
  'request_headers' => {
    'X-Original-URI' => opts['request_uri'],
  },
  'cookies' => {}
}

# Parse cookies string into a hash
opts['cookies'].split(/;\s*/).each do |pair|
  next if pair.nil? || pair.empty? || !pair.include?('=')
  k, v = pair.split('=', 2)
  next if k.nil? || k.empty?
  context['cookies'][k] = v
end

delegate = CustomDelegate.new
delegate.context = context

ok = if delegate.respond_to?(:authorize)
  delegate.authorize
else
  delegate.pre_authorize
end
puts({ authorized: ok }.to_json)
exit(ok ? 0 : 1)
