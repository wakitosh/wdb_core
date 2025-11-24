#!/usr/bin/env ruby
# Minimal harness to validate README's delegates.rb sample.
# Usage:
#   DRUPAL_AUTH_ENDPOINT="https://your.host.name/wdb/api/cantaloupe_auth" \
#   ruby delegate_harness.rb \
#     --identifier wdb/hdb/bm10221/1.ptif \
#     --cookies "SSESSxxxx=abc; _ga=1" \
#     --client-ip 203.0.113.9 \
#     --request-uri "/iiif/3/wdb%2Fhdb%2Fbm10221%2F1.ptif/full/max/0/default.jpg?wdb_token=YOUR.TOKEN.VALUE"
#
# Returns exit status 0 if authorized, 1 otherwise.

require 'net/http'
require 'uri'
require 'json'
require_relative 'delegate'
require 'optparse'

DRUPAL_AUTH_ENDPOINT = ENV['DRUPAL_AUTH_ENDPOINT'] || nil

opts = {
  'identifier' => nil,
  'cookies' => '',
  'client_ip' => '127.0.0.1',
  'request_uri' => '/iiif/3/info.json'
}

OptionParser.new do |o|
  o.on('--identifier ID', 'IIIF identifier, e.g., wdb/hdb/foo/1.ptif') { |v| opts['identifier'] = v }
  o.on('--cookies STR', 'Cookie header string, e.g., "SSESS...=...; _ga=..."') { |v| opts['cookies'] = v }
  o.on('--client-ip IP', 'Client IP (default 127.0.0.1)') { |v| opts['client_ip'] = v }
  o.on('--request-uri URI', 'Request URI (default /iiif/3/info.json)') { |v| opts['request_uri'] = v }
end.parse!

if DRUPAL_AUTH_ENDPOINT.nil? || DRUPAL_AUTH_ENDPOINT.empty?
  warn 'DRUPAL_AUTH_ENDPOINT must be set as an environment variable.'
  exit 2
end

# Emulate Cantaloupe's context accessor.
$context = {
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
  $context['cookies'][k] = v
end

def context
  $context
end

  # Token extraction and pre_authorize are provided by delegates.rb

ok = pre_authorize
puts({ authorized: ok }.to_json)
exit(ok ? 0 : 1)
