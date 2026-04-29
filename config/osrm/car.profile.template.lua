-- FuelAU custom OSRM profile template
-- Copy this to the file you mount as /opt/car.lua for osrm-extract.
-- Fill in the placeholders marked EDIT ME.

api_version = 4

local profile = {}

profile.properties = {
    -- EDIT ME: keep or change these depending on your routing strategy.
    weight_name = 'routability',
    max_speed_for_map_matching = 140,
}

profile.default_mode = 'driving'
profile.default_speed = 110

-- EDIT ME: customize these defaults for Australia-specific routing behavior.
profile.speed_profile = {
    motorway = 110,
    trunk = 100,
    primary = 90,
    secondary = 80,
    tertiary = 70,
    unclassified = 60,
    residential = 50,
    service = 30,
    track = 20,
}

profile.excluded_highway_types = {
    -- EDIT ME: add road classes you want to avoid entirely.
}

profile.ferry_speeds = {
    -- EDIT ME: add ferry speed assumptions if you want ferries allowed.
}

profile.access_tag_whitelist = {
    yes = true,
    permissive = true,
    designated = true,
    destination = true,
    private = false,
    no = false,
}

local function normalize_highway(value)
    if type(value) ~= 'string' then
        return nil
    end
    return value:lower()
end

local function is_excluded_highway(highway)
    highway = normalize_highway(highway)
    if not highway then
        return false
    end
    return profile.excluded_highway_types[highway] == true
end

function profile.setup()
    -- EDIT ME: adjust penalties, turn costs, and access handling here.
    return {
        default_mode = profile.default_mode,
        default_speed = profile.default_speed,
        properties = profile.properties,
    }
end

function profile.process_way(way, result, data)
    -- EDIT ME: this is where road eligibility and speed rules go.
    local highway = way:get_value_by_key('highway')

    if is_excluded_highway(highway) then
        result.forward_mode = 0
        result.backward_mode = 0
        return
    end

    local access = way:get_value_by_key('access')
    if access and profile.access_tag_whitelist[access] == false then
        result.forward_mode = 0
        result.backward_mode = 0
        return
    end

    local speed = profile.speed_profile[normalize_highway(highway)] or profile.default_speed

    -- EDIT ME: add wet-weather, school-zone, unsealed-road, or time-based modifiers.
    result.forward_speed = speed
    result.backward_speed = speed

    -- EDIT ME: control whether the way is routable.
    result.forward_mode = 1
    result.backward_mode = 1

    -- EDIT ME: add truck, toll, or fuel-economic penalties here if needed.
    if way:get_value_by_key('toll') == 'yes' then
        result.weight = (result.weight or 0) + 0
    end
end

function profile.process_node(node, result)
    -- EDIT ME: use for traffic lights, barriers, access gates, and similar node-level rules.
    local barrier = node:get_value_by_key('barrier')
    if barrier == 'gate' or barrier == 'lift_gate' then
        -- Example placeholder; tune or remove as required.
        result.barrier = true
    end
end

function profile.process_turn(turn)
    -- EDIT ME: increase turn costs for difficult maneuvers, ferries, or unsealed junctions.
    return turn
end

return profile
