-- FuelMiser custom OSRM profile template
--
-- Important limitation:
-- OSRM profiles are applied during extraction, not at route-query time.
-- That means the Lua profile cannot know the trip length up front, so the
-- 30 km short/long distinction has to be encoded as a blended routing policy
-- here, or chosen outside OSRM by selecting different extracted datasets.
--
-- This template keeps both tuning blocks visible so you can adjust them
-- without reworking the rest of the file.

api_version = 4

local profile = {}

profile.route_length_threshold_km = 30
profile.route_strategy = 'hybrid' -- short, long, or hybrid

profile.properties = {
    weight_name = 'duration',
    max_speed_for_map_matching = 110,
}

profile.default_mode = 'driving'
profile.default_speed = 90

profile.speed_profile = {
    motorway = 110,
    trunk = 100,
    primary = 90,
    secondary = 80,
    tertiary = 70,
    unclassified = 60,
    residential = 50,
    living_street = 30,
    service = 30,
    track = 20,
}

profile.road_classes = {
    major = {
        motorway = true,
        motorway_link = true,
        trunk = true,
        trunk_link = true,
        primary = true,
        primary_link = true,
    },
    local = {
        residential = true,
        living_street = true,
        service = true,
        unclassified = true,
        tertiary = true,
        tertiary_link = true,
        track = true,
    },
}

profile.short_mode = {
    name = 'short',
    road_speed_multiplier = {
        major = 1.00,
        local = 1.00,
        other = 1.00,
    },
    turn_penalty_seconds = {
        traffic_light = 12,
        stop = 8,
        give_way = 5,
        roundabout = 4,
        uncontrolled = 0,
        left = 1,
        right = 4,
    },
    major_road_bias_seconds = 0,
}

profile.long_mode = {
    name = 'long',
    road_speed_multiplier = {
        major = 1.20,
        local = 0.90,
        other = 1.00,
    },
    turn_penalty_seconds = {
        traffic_light = 12,
        stop = 8,
        give_way = 5,
        roundabout = 4,
        uncontrolled = 0,
        left = 1,
        right = 4,
    },
    major_road_bias_seconds = 0,
}

profile.hybrid_mode = {
    name = 'hybrid',
    road_speed_multiplier = {
        major = 1.10,
        local = 0.95,
        other = 1.00,
    },
    turn_penalty_seconds = {
        traffic_light = 12,
        stop = 8,
        give_way = 5,
        roundabout = 4,
        uncontrolled = 0,
        left = 1,
        right = 4,
    },
    major_road_bias_seconds = 0,
}

profile.excluded_highway_types = {
    -- EDIT ME: add road classes you want to avoid entirely.
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

local function current_mode()
    if profile.route_strategy == 'short' then
        return profile.short_mode
    end
    if profile.route_strategy == 'long' then
        return profile.long_mode
    end
    return profile.hybrid_mode
end

local function is_excluded_highway(highway)
    highway = normalize_highway(highway)
    if not highway then
        return false
    end
    return profile.excluded_highway_types[highway] == true
end

local function road_class(highway)
    highway = normalize_highway(highway)
    if not highway then
        return 'other'
    end
    if profile.road_classes.major[highway] then
        return 'major'
    end
    if profile.road_classes["local"][highway] then
        return 'local'
    end
    return 'other'
end

local function apply_turn_penalty(turn, seconds)
    if seconds == 0 then
        return
    end

    turn.duration = (turn.duration or 0) + seconds
    turn.weight = (turn.weight or 0) + seconds
end

function profile.setup()
    return {
        default_mode = profile.default_mode,
        default_speed = profile.default_speed,
        properties = profile.properties,
        highway_turn_classification = {
            motorway = 3,
            motorway_link = 3,
            trunk = 2,
            trunk_link = 2,
            primary = 1,
            primary_link = 1,
            secondary = 0,
            tertiary = 0,
            unclassified = -1,
            residential = -2,
            living_street = -2,
            service = -3,
            track = -4,
        },
    }
end

function profile.process_way(way, result, data)
    local highway = normalize_highway(way:get_value_by_key('highway'))

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

    local mode = current_mode()
    local class = road_class(highway)
    local speed = profile.speed_profile[highway] or profile.default_speed
    local multiplier = mode.road_speed_multiplier[class] or mode.road_speed_multiplier.other

    -- Short trips should stay relaxed and fast on local streets.
    -- Long trips should drift toward major roads earlier.
    result.forward_speed = speed * multiplier
    result.backward_speed = speed * multiplier
    result.forward_mode = 1
    result.backward_mode = 1

    if way:get_value_by_key('toll') == 'yes' then
        result.forward_speed = result.forward_speed * 0.95
        result.backward_speed = result.backward_speed * 0.95
    end
end

function profile.process_node(node, result)
    local mode = current_mode()

    local barrier = node:get_value_by_key('barrier')
    if barrier == 'gate' or barrier == 'lift_gate' then
        result.barrier = true
        return
    end

    local highway = normalize_highway(node:get_value_by_key('highway'))
    if highway == 'traffic_signals' then
        obstacle_map:add(node, Obstacle.new(obstacle_type.traffic_signals, obstacle_direction.both, mode.turn_penalty_seconds.traffic_light, 0))
        return
    end

    if highway == 'stop' then
        obstacle_map:add(node, Obstacle.new(obstacle_type.stop, obstacle_direction.both, mode.turn_penalty_seconds.stop, 0))
        return
    end

    if highway == 'give_way' then
        obstacle_map:add(node, Obstacle.new(obstacle_type.give_way, obstacle_direction.both, mode.turn_penalty_seconds.give_way, 0))
        return
    end

    local junction = node:get_value_by_key('junction')
    if junction == 'roundabout' or junction == 'mini_roundabout' then
        obstacle_map:add(node, Obstacle.new(obstacle_type.mini_roundabout, obstacle_direction.both, mode.turn_penalty_seconds.roundabout, 0))
    end
end

function profile.process_turn(turn)
    local mode = current_mode()

    local obstacles = obstacle_map:get(turn.from, turn.via) or {}
    for _, obs in pairs(obstacles) do
        if obs.type == obstacle_type.traffic_signals then
            apply_turn_penalty(turn, mode.turn_penalty_seconds.traffic_light)
        elseif obs.type == obstacle_type.stop then
            apply_turn_penalty(turn, mode.turn_penalty_seconds.stop)
        elseif obs.type == obstacle_type.give_way then
            apply_turn_penalty(turn, mode.turn_penalty_seconds.give_way)
        elseif obs.type == obstacle_type.mini_roundabout then
            apply_turn_penalty(turn, mode.turn_penalty_seconds.roundabout)
        elseif obs.type == obstacle_type.barrier then
            apply_turn_penalty(turn, 30)
        end
    end

    if turn.is_u_turn then
        apply_turn_penalty(turn, 20)
        return
    end

    -- In left-hand traffic, positive angles are right turns and negative angles are left turns.
    if turn.angle > 20 then
        apply_turn_penalty(turn, mode.turn_penalty_seconds.right)
    elseif turn.angle < -20 then
        apply_turn_penalty(turn, mode.turn_penalty_seconds.left)
    end

    local source_class = turn.source_highway_turn_classification or 0
    local target_class = turn.target_highway_turn_classification or 0

    -- Long trips should reach and stay on major roads earlier.
    if mode.major_road_bias_seconds ~= 0 and (source_class >= 1 or target_class >= 1) then
        apply_turn_penalty(turn, mode.major_road_bias_seconds)
    end
end

return profile
