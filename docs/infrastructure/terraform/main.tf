# Terraform example: AppSync Event API for hds-solutions/laravel-appsync-events
#
# Requires hashicorp/aws provider >= 6.9.0 (the release that added
# aws_appsync_api and aws_appsync_channel_namespace).
#
# Validated: resource arguments checked against the AWS provider docs for
# aws_appsync_api / aws_appsync_channel_namespace (provider >= 6.9.0).
# NOT validated: `terraform fmt` / `terraform validate` (no Terraform binary
# available in the authoring environment) and no apply against AWS.

terraform {
  required_version = ">= 1.5.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = ">= 6.9.0"
    }
  }
}

variable "api_name" {
  description = "Name of the Event API."
  type        = string
}

variable "authorizer_function_arn" {
  description = "ARN of the Lambda function running the channel authorizer."
  type        = string
}

variable "namespaces" {
  description = "AppSync channel namespaces to create — the authorization boundary channel names map onto (`private-` names under `private`, everything else under `public`)."
  type        = set(string)
  default     = ["public", "private"]
}

variable "public_channels" {
  description = "Enable API_KEY provider so anonymous/public channels can connect and subscribe."
  type        = bool
  default     = false
}

resource "aws_appsync_api" "events" {
  name = var.api_name

  event_config {
    auth_provider {
      auth_type = "AWS_LAMBDA"
      lambda_authorizer_config {
        authorizer_uri                   = var.authorizer_function_arn
        authorizer_result_ttl_in_seconds = 300
      }
    }

    auth_provider {
      auth_type = "AWS_IAM"
    }

    dynamic "auth_provider" {
      for_each = var.public_channels ? [1] : []
      content {
        auth_type = "API_KEY"
      }
    }

    connection_auth_mode {
      auth_type = "AWS_LAMBDA"
    }

    dynamic "connection_auth_mode" {
      for_each = var.public_channels ? [1] : []
      content {
        auth_type = "API_KEY"
      }
    }

    default_publish_auth_mode {
      auth_type = "AWS_IAM"
    }

    default_subscribe_auth_mode {
      auth_type = "AWS_LAMBDA"
    }

    dynamic "default_subscribe_auth_mode" {
      for_each = var.public_channels ? [1] : []
      content {
        auth_type = "API_KEY"
      }
    }
  }
}

resource "aws_appsync_channel_namespace" "namespaces" {
  for_each = var.namespaces

  api_id = aws_appsync_api.events.api_id
  name   = each.value

  publish_auth_mode {
    auth_type = "AWS_IAM"
  }

  # `private` subscribes only via the Lambda authorizer; other namespaces
  # also accept API_KEY when public channels are enabled.
  dynamic "subscribe_auth_mode" {
    for_each = each.value == "private" ? ["AWS_LAMBDA"] : (
      var.public_channels ? ["AWS_LAMBDA", "API_KEY"] : ["AWS_LAMBDA"]
    )
    content {
      auth_type = subscribe_auth_mode.value
    }
  }
}

resource "aws_lambda_permission" "appsync_authorizer" {
  action        = "lambda:InvokeFunction"
  function_name = var.authorizer_function_arn
  principal     = "appsync.amazonaws.com"
  source_arn    = aws_appsync_api.events.api_arn
}

# Attach this policy to the IAM role your Laravel servers/tasks run as.
data "aws_iam_policy_document" "publish" {
  statement {
    actions = ["appsync:EventPublish"]

    resources = [
      for ns in var.namespaces :
      "${aws_appsync_api.events.api_arn}/channelNamespace/${ns}"
    ]
  }
}

resource "aws_iam_policy" "publish" {
  name   = "${var.api_name}-publish"
  policy = data.aws_iam_policy_document.publish.json
}

# Endpoint outputs match the AppSync dns map (keys "HTTP" and "REALTIME").
output "http_endpoint" {
  description = "Value for APPSYNC_HTTP_ENDPOINT."
  value       = "https://${aws_appsync_api.events.dns["HTTP"]}/event"
}

output "realtime_endpoint" {
  description = "Value for APPSYNC_REALTIME_ENDPOINT."
  value       = "wss://${aws_appsync_api.events.dns["REALTIME"]}/event/realtime"
}

output "api_id" {
  value = aws_appsync_api.events.api_id
}
