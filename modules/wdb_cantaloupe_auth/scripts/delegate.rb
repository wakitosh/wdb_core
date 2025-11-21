# frozen_string_literal: true
# JRuby delegate script for Cantaloupe pre-authorization against Drupal (WDB).
# Copy this file to your Cantaloupe delegates path (e.g., delegates.rb or require it
# from your existing delegate) and set the environment variable DRUPAL_AUTH_ENDPOINT
# to your Drupal site's endpoint, e.g. https://your.host.name/wdb/api/cantaloupe_auth
# Optionally set WDB_TOKEN_PARAM (default: "wdb_token").

require 'net/http'
require 'uri'
require 'json'

DRUPAL_AUTH_ENDPOINT = ENV['DRUPAL_AUTH_ENDPOINT']
TOKEN_QUERY_PARAM    = ENV['WDB_TOKEN_PARAM'] || 'wdb_token'
TOKEN_HEADER_CANDIDATES = [
  'X-Wdb-Token',
  'X-Original-URI',
  'X-Original-URL',
  'X-Forwarded-URI',
  'X-Forwarded-URL',
]

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
  token = extract_token_from_string(context['request_uri'])
  return token if token && !token.empty?

  # Try forwarded headers
  headers = context['request_headers'] || {}
  downcased = {}
  headers.each { |k, v| downcased[k.to_s.downcase] = v if k }

  TOKEN_HEADER_CANDIDATES.each do |header_name|
    candidate = headers[header_name] || downcased[header_name.downcase]
    token = extract_token_from_string(candidate)
    return token if token && !token.empty?
  end

  # Finally, local URI (if present)
  local_uri = context['local_uri']
  extract_token_from_string(local_uri)
end

# Main pre-authorization entrypoint for Cantaloupe.
# Returns true to allow, false to deny.
def pre_authorize(options = {})
  # Allow info.json unconditionally.
  return true if context['request_uri'].to_s.end_with?('info.json')

  # Allow requests from the server itself (e.g., for derivative generation).
  return true if context['client_ip'].to_s.start_with?('127.0.0.1')

  token = resolve_token_from_context
  cookies_hash = context['cookies'] || {}
  cookies = cookies_hash.map { |k, v| "#{k}=#{v}" }

  payload_hash = {
    identifier: context['identifier'],
    request_uri: context['request_uri'],
  }
  payload_hash[:token] = token if token && !token.empty?
  payload_hash[:cookies] = cookies unless cookies.empty?
  payload = payload_hash.to_json

  begin
    endpoint = DRUPAL_AUTH_ENDPOINT
    return false if endpoint.nil? || endpoint.empty?

    uri = URI.parse(endpoint)
    http = Net::HTTP.new(uri.host, uri.port)
    http.use_ssl = (uri.scheme == 'https')

    request = Net::HTTP::Post.new(uri.request_uri, 'Content-Type' => 'application/json')
    request.body = payload

    response = http.request(request)

    if response.is_a?(Net::HTTPSuccess)
      auth_result = JSON.parse(response.body)
      return !!auth_result['authorized']
    else
      return false
    end
  rescue => _e
    return false
  end
end
